<?php
// Pemanggil memeriksa login, token CSRF, dan membuka transaksi terlebih dahulu.
function prosesLayanan($db, $aksi, $id, &$files) {
    if ((!isAdmin() && !isTeller()) || !$db->inTransaction()) throw new Exception('Akses layanan ditolak atau transaksi belum dibuka.');
    $p = layananPinjaman($db, $id);
    layananSegarkanDenda($db,$p);
    if(in_array($aksi,['jual_jaminan','kembalikan_lebih','bayar_bonus','repeat_order','ajukan_hapus_denda','putuskan_hapus_denda'],true)) {
        prosesLayananLanjutan($db,$p,$aksi,$files);return;
    }
    $uid = (int) $_SESSION['user_id'];
    $catatan = trim((string) ($_POST['catatan'] ?? ''));
    if (strlen($catatan) > 2000) throw new Exception('Catatan maksimal 2000 karakter.');
    if ($aksi === 'lapor_macet') {
        $s = $db->prepare("SELECT GREATEST(0,DATEDIFF(CURDATE(),MIN(tanggal_jatuh_tempo))) FROM angsuran WHERE pinjaman_id=? AND status<>'lunas'");
        $s->execute([$id]); $hari = (int) $s->fetchColumn();
        if ($p['status'] === 'lunas' || $hari < 1) throw new Exception('Laporan hanya untuk nasabah yang terlambat.');
        if (strlen($catatan) < 5) throw new Exception('Catatan laporan minimal 5 karakter.');
        $s = $db->prepare("SELECT COUNT(*) FROM layanan_laporan_macet WHERE pinjaman_id=? AND status='menunggu'");
        $s->execute([$id]);
        if ($s->fetchColumn()) throw new Exception('Masih ada laporan menunggu keputusan Admin.');
        $sisa = layananSisa($db,$id);
        $db->prepare('INSERT INTO layanan_laporan_macet (pinjaman_id,user_id,hari_telat,sisa_hutang,catatan) VALUES (?,?,?,?,?)')->execute([$id,$uid,$hari,$sisa['total'],$catatan]);
    } elseif ($aksi === 'keputusan_macet') {
        layananAdmin();
        if (strlen($catatan) < 5) throw new Exception('Catatan keputusan wajib diisi.');
        $keputusan = $_POST['keputusan'] ?? '';
        if (!in_array($keputusan,['ditindaklanjuti','selesai','ditolak'],true)) throw new Exception('Keputusan tidak valid.');
        $db->prepare("UPDATE layanan_laporan_macet SET status=?,keputusan=?,admin_id=?,diputuskan_at=NOW() WHERE id=? AND pinjaman_id=? AND status='menunggu'")->execute([$keputusan,$catatan,$uid,(int)$_POST['laporan_id'],$id]);
    } elseif ($aksi === 'reschedule') {
        layananAdmin();
        $tanggal = $_POST['tanggal'] ?? '';
        $dt = DateTime::createFromFormat('!Y-m-d',$tanggal);
        if (!$dt || $dt->format('Y-m-d') !== $tanggal || $tanggal <= date('Y-m-d') || strlen($catatan) < 5) throw new Exception('Isi jatuh tempo baru setelah hari ini dan alasan perpanjangan.');
        if ($p['status'] === 'lunas') throw new Exception('Pinjaman sudah lunas.');
        $s = $db->prepare("SELECT MIN(tanggal_jatuh_tempo) FROM angsuran WHERE pinjaman_id=? AND status<>'lunas'"); $s->execute([$id]); $lama=$s->fetchColumn();
        if (!$lama || $tanggal <= $lama) throw new Exception('Tanggal baru harus setelah jatuh tempo lama.');
        $geser=(new DateTime($lama))->diff(new DateTime($tanggal))->days;
        // Pertahankan denda yang sudah terutang; reschedule tidak memutihkan diam-diam.
        $db->prepare("UPDATE angsuran SET tanggal_jatuh_tempo=DATE_ADD(tanggal_jatuh_tempo,INTERVAL $geser DAY),denda_manual=1,status='belum_bayar' WHERE pinjaman_id=? AND status<>'lunas'")->execute([$id]);
        $db->prepare('UPDATE pinjaman SET jatuh_tempo=(SELECT MAX(tanggal_jatuh_tempo) FROM angsuran WHERE pinjaman_id=?) WHERE id=?')->execute([$id,$id]);
        $catatan='Jadwal digeser '.$geser.' hari. '.$catatan;
    } elseif ($aksi === 'eksekusi') {
        layananAdmin();
        if ($p['status']==='lunas' || !$p['memerlukan_jaminan'] || strlen($catatan)<5) throw new Exception('Eksekusi membutuhkan pinjaman belum lunas dengan jaminan dan catatan keputusan.');
        $stmt=$db->prepare("UPDATE jaminan_pinjaman SET status_jaminan='dilelang' WHERE pinjaman_id=? AND status_jaminan='dititipkan'");$stmt->execute([$id]);
        if (!$stmt->rowCount()) throw new Exception('Jaminan tidak tersedia untuk dieksekusi.');
        $catatan='Keputusan eksekusi jaminan (hasil penjualan belum mengurangi hutang): '.$catatan;
    } elseif ($aksi === 'bayar_denda') {
        if (!isAdmin() && (isset($_POST['diskon']) || isset($_POST['tarif']) || isset($_POST['denda_akhir']))) {
            throw new Exception('Tarif, diskon, dan penghapusan denda hanya untuk Admin.');
        }
        if ($p['status']==='lunas') throw new Exception('Pinjaman lunas terkunci.');
        $stmt=$db->prepare("SELECT * FROM angsuran WHERE id=? AND pinjaman_id=? AND status<>'lunas' FOR UPDATE");$stmt->execute([(int)$_POST['angsuran_id'],$id]);$a=$stmt->fetch(PDO::FETCH_ASSOC);
        if (!$a) throw new Exception('Angsuran tidak ditemukan atau sudah lunas.');
        $d=hitungDendaAngsuran($db,$a,$p['jumlah_pinjaman']);
        $denda=!empty($a['denda_manual'])?(float)$a['denda']:$d['denda'];
        $stmt=$db->prepare('SELECT COALESCE(SUM(jumlah),0) FROM layanan_bayar_denda WHERE angsuran_id=?');$stmt->execute([$a['id']]);$paid=(float)$stmt->fetchColumn();
        $jumlah=layananNominal($_POST['jumlah'] ?? '');
        if ($jumlah > round($denda-$paid,2)) throw new Exception('Pembayaran melebihi sisa denda.');
        $metode=$_POST['metode'] ?? ''; if (!in_array($metode,['cash','transfer'],true)) throw new Exception('Metode tidak valid.');
        $foto=validasiFotoUpload('bukti','Bukti pembayaran denda',5*1024*1024,true);
        $path=layananSimpanFoto($foto,'pembayaran');$files[]=$path;
        $tid=layananCatatKas($db,$p,$jumlah,'Pembayaran denda angsuran ke-'.$a['angsuran_ke'].' - '.$p['kode_pinjaman']);
        $db->prepare('INSERT INTO layanan_bayar_denda (angsuran_id,pinjaman_id,user_id,jumlah,metode,bukti,transaksi_id,hari_telat,tarif,denda_awal,denda_akhir) VALUES (?,?,?,?,?,?,?,?,?,?,?)')->execute([$a['id'],$id,$uid,$jumlah,$metode,$path,$tid,$d['hari_telat'],$d['per_hari'],$d['denda'],$denda]);
        $nasabah=$db->prepare('SELECT nama_lengkap FROM anggota WHERE id=?');$nasabah->execute([$p['anggota_id']]);
        $catatan='Input pembayaran denda Rp '.$jumlah.' untuk '.$nasabah->fetchColumn().'; angsuran '.$a['id'].'; '.$metode;
    } elseif ($aksi === 'diskon_denda') {
        layananAdmin();
        if ($p['status']==='lunas' || strlen($catatan)<5) throw new Exception('Isi alasan diskon pada pinjaman yang belum lunas.');
        if (!$db->query('SELECT pemutihan FROM layanan_pengaturan WHERE id=1')->fetchColumn()) throw new Exception('Pemutihan sedang dinonaktifkan.');
        $stmt=$db->prepare("SELECT * FROM angsuran WHERE id=? AND pinjaman_id=? AND status<>'lunas' FOR UPDATE");$stmt->execute([(int)$_POST['angsuran_id'],$id]);$a=$stmt->fetch(PDO::FETCH_ASSOC);
        if (!$a) throw new Exception('Angsuran tidak ditemukan.');
        $akhir=layananNominal($_POST['jumlah'] ?? '',true);
        $stmt=$db->prepare('SELECT COALESCE(SUM(jumlah),0) FROM layanan_bayar_denda WHERE angsuran_id=?');$stmt->execute([$a['id']]);$paid=(float)$stmt->fetchColumn();
        if ($akhir < $paid || $akhir > (float)$a['denda']) throw new Exception('Denda akhir harus antara jumlah yang sudah dibayar dan denda saat ini.');
        $db->prepare('UPDATE angsuran SET denda=?,denda_manual=1 WHERE id=?')->execute([$akhir,$a['id']]);
        $catatan='Angsuran '.$a['id'].'; denda awal '.$a['denda'].'; akhir '.$akhir.'; '.$catatan;
    } elseif ($aksi === 'approve_denda') {
        layananAdmin();
        $s=$db->prepare('UPDATE layanan_bayar_denda SET approved_by=? WHERE id=? AND pinjaman_id=? AND approved_by IS NULL');$s->execute([$uid,(int)($_POST['bayar_id']??0),$id]);
        if(!$s->rowCount())throw new Exception('Pembayaran tidak ditemukan atau sudah disetujui.');
    } elseif ($aksi === 'ajukan_lunas') {
        if ($p['status']==='lunas') throw new Exception('Pinjaman sudah lunas.');
        $stmt=$db->prepare('SELECT * FROM layanan_pelunasan WHERE pinjaman_id=? FOR UPDATE');$stmt->execute([$id]);$old=$stmt->fetch(PDO::FETCH_ASSOC);
        if ($old && $old['status']!=='ditolak') throw new Exception('Pengajuan sudah ada.');
        if ($old) $db->prepare("UPDATE layanan_pelunasan SET status='menunggu',user_id=?,admin_id=NULL,diskon_persen=0,catatan=?,created_at=NOW() WHERE pinjaman_id=?")->execute([$uid,$catatan,$id]);
        else $db->prepare('INSERT INTO layanan_pelunasan (pinjaman_id,user_id,catatan) VALUES (?,?,?)')->execute([$id,$uid,$catatan]);
    } elseif ($aksi === 'approve_lunas' || $aksi === 'tolak_lunas') {
        layananAdmin();
        $diskon=layananNominal($_POST['diskon'] ?? '0',true);
        if ($diskon>100 || $p['status']==='lunas') throw new Exception('Diskon harus 0–100% dan pinjaman belum lunas.');
        $stmt=$db->prepare("UPDATE layanan_pelunasan SET status=?,diskon_persen=?,admin_id=?,diputuskan_at=NOW(),catatan=? WHERE pinjaman_id=? AND status='menunggu'");
        $stmt->execute([$aksi==='approve_lunas'?'disetujui':'ditolak',$diskon,$uid,$catatan,$id]);
        if (!$stmt->rowCount()) throw new Exception('Tidak ada pengajuan yang menunggu.');
    } elseif ($aksi === 'proses_lunas' || $aksi === 'pelunasan_langsung') {
        if ($p['status']==='lunas') throw new Exception('Pelunasan sudah diproses.');
        $stmt=$db->prepare("SELECT * FROM layanan_pelunasan WHERE pinjaman_id=? AND status='disetujui' FOR UPDATE");$stmt->execute([$id]);$approval=$stmt->fetch(PDO::FETCH_ASSOC);
        if ($aksi==='pelunasan_langsung') {
            // Pelunasan biasa tidak memberikan diskon; keputusan Admin yang sudah ada tetap berlaku.
            if (!$approval) $approval=['diskon_persen'=>0,'admin_id'=>null];
        } elseif (!$approval) throw new Exception('Pelunasan dipercepat wajib disetujui Admin terlebih dahulu.');
        $s=layananSisa($db,$id,$approval['diskon_persen']);
        $jumlah=layananNominal($_POST['jumlah'] ?? '',true);
        if (abs($jumlah-$s['total'])>0.001 || !$s['jumlah']) throw new Exception('Nominal harus sama dengan sisa tagihan terbaru setelah diskon Admin.');
        if ($s['denda']-$s['denda_dibayar']>0.005) throw new Exception('Bayar sisa denda melalui menu Denda terlebih dahulu. Foto bukti pembayaran denda wajib dicatat.');
        $metode=$_POST['metode'] ?? '';if (!in_array($metode,['cash','transfer'],true)) throw new Exception('Metode tidak valid.');
        if ($aksi!=='pelunasan_langsung' && $p['memerlukan_jaminan'] && empty($_POST['cek_fisik'])) throw new Exception('Verifikasi fisik jaminan wajib dilakukan.');
        $path='';
        if ($aksi!=='pelunasan_langsung') {
            $foto=validasiFotoUpload('bukti','Bukti pelunasan',5*1024*1024,true);$path=layananSimpanFoto($foto,'pembayaran');$files[]=$path;
        }
        if($jumlah>0)layananCatatKas($db,$p,$jumlah,'Pelunasan dipercepat - '.$p['kode_pinjaman'].'; diskon bunga '.$s['diskon_bunga']);
        if($s['kredit_lelang']>0) {
            $first=$db->prepare("SELECT id FROM angsuran WHERE pinjaman_id=? AND status<>'lunas' ORDER BY angsuran_ke LIMIT 1");$first->execute([$id]);
            layananPakaiKredit($db,$id,$first->fetchColumn(),$s['kredit_terpakai']);
            $db->prepare('UPDATE layanan_lelang SET kelebihan=kelebihan+kredit,kredit=0 WHERE pinjaman_id=?')->execute([$id]);
        }
        $db->prepare("UPDATE angsuran SET status='lunas',tanggal_bayar=CURDATE(),cara_bayar='pelunasan' WHERE pinjaman_id=? AND status<>'lunas'")->execute([$id]);
        $db->prepare('INSERT INTO pelunasan_dini (pinjaman_id,jumlah_pelunasan,keterangan,user_id) VALUES (?,?,?,?)')->execute([$id,$jumlah,($approval['admin_id']?'Disetujui Admin '.$approval['admin_id']:'Pelunasan tanpa diskon').'; diskon bunga '.$s['diskon_bunga'],$uid]);
        $db->prepare("INSERT INTO layanan_pembayaran (pinjaman_id,user_id,jenis,jumlah,metode,bukti,bunga_diskon) VALUES (?,?,'pelunasan',?,?,?,?)")->execute([$id,$uid,$jumlah,$metode,$path,$s['diskon_bunga']]);
        $db->prepare("UPDATE layanan_pelunasan SET status='selesai' WHERE pinjaman_id=?")->execute([$id]);
        tandaiLunasBilaSelesai($db,$id);
        $catatan='Pelunasan Rp '.$jumlah.'; diskon bunga '.$s['diskon_bunga'];
    } elseif ($aksi === 'ajukan_jaminan') {
        if ($p['status']!=='lunas' || !$p['memerlukan_jaminan']) throw new Exception('Hanya untuk pinjaman lunas dengan jaminan.');
        $stmt=$db->prepare("SELECT COUNT(*) FROM jaminan_pinjaman WHERE pinjaman_id=? AND status_jaminan='dititipkan' AND foto_barang IS NOT NULL AND foto_barang<>''");$stmt->execute([$id]);
        if (!$stmt->fetchColumn()) throw new Exception('Jaminan harus tersedia dan memiliki foto saat diterima.');
        $db->prepare('INSERT INTO layanan_jaminan_kembali (pinjaman_id,user_id) VALUES (?,?)')->execute([$id,$uid]);
    } elseif ($aksi === 'approve_jaminan') {
        layananAdmin();
        if ($p['status']!=='lunas') throw new Exception('Pinjaman belum lunas.');
        $s=$db->prepare("UPDATE layanan_jaminan_kembali SET status='disetujui',admin_id=? WHERE pinjaman_id=? AND status='menunggu'");$s->execute([$uid,$id]);
        if(!$s->rowCount())throw new Exception('Pengembalian tidak ditemukan atau sudah diproses.');
    } elseif ($aksi === 'serahkan_jaminan') {
        if ($p['status']!=='lunas' || empty($_POST['cek_fisik'])) throw new Exception('Pinjaman harus lunas dan fisik jaminan harus diperiksa.');
        $stmt=$db->prepare("SELECT * FROM layanan_jaminan_kembali WHERE pinjaman_id=? AND status='disetujui' FOR UPDATE");$stmt->execute([$id]);
        if (!$stmt->fetch()) throw new Exception('Pengembalian wajib mendapat persetujuan Admin.');
        $foto=validasiFotoUpload('bukti','Foto serah terima jaminan',5*1024*1024,true);
        $ttd=$_POST['ttd'] ?? '';
        if (strlen($ttd)>300000 || !preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#D',$ttd,$matches)) throw new Exception('Tanda tangan nasabah wajib diisi.');
        $raw=base64_decode($matches[1],true);$info=$raw!==false?@getimagesizefromstring($raw):false;
        if (!$info || $info['mime']!=='image/png') throw new Exception('Tanda tangan tidak valid.');
        $path=layananSimpanFoto($foto,'jaminan_kembali');$files[]=$path;
        $db->prepare("UPDATE layanan_jaminan_kembali SET status='diserahkan',foto=?,ttd=?,diserahkan_at=NOW(),user_id=? WHERE pinjaman_id=?")->execute([$path,$ttd,$uid,$id]);
        $stmt=$db->prepare("UPDATE jaminan_pinjaman SET status_jaminan='dikembalikan',tanggal_dikembalikan=CURDATE() WHERE pinjaman_id=? AND status_jaminan='dititipkan'");$stmt->execute([$id]);
        if (!$stmt->rowCount()) throw new Exception('Jaminan sudah diserahkan atau dieksekusi.');
    } elseif ($aksi === 'prioritas') {
        if ($p['status']!=='lunas') throw new Exception('Pengajuan prioritas hanya untuk nasabah lunas.');
        $db->prepare('INSERT INTO layanan_prioritas (pinjaman_id,anggota_id,user_id) VALUES (?,?,?)')->execute([$id,$p['anggota_id'],$uid]);
    } elseif ($aksi === 'approve_prioritas') {
        layananAdmin();
        if (!layananPrioritasLayak($db,$p['anggota_id'])) throw new Exception('Prioritas memerlukan dua pinjaman lunas terakhir tanpa keterlambatan.');
        $s=$db->prepare("UPDATE layanan_prioritas SET status='disetujui',admin_id=? WHERE pinjaman_id=? AND status='menunggu'");$s->execute([$uid,$id]);
        if(!$s->rowCount())throw new Exception('Pengajuan prioritas tidak ditemukan atau sudah diproses.');
    } else throw new Exception('Tindakan tidak dikenal.');
    layananAudit($db,$id,$aksi,$catatan);
}
