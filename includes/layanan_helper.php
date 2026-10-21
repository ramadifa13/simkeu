<?php
// Aturan layanan pinjaman dipakai bersama oleh Admin dan Teller.
function layananEscape($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }

function nomorWhatsApp($nomor) {
    $nomor = preg_replace('/[^0-9]/', '', (string) $nomor);
    if (substr($nomor, 0, 1) === '0') $nomor = '62' . substr($nomor, 1);
    elseif (substr($nomor, 0, 1) === '8') $nomor = '62' . $nomor;
    return preg_match('/^[1-9][0-9]{7,14}$/D', $nomor) ? $nomor : '';
}
function tombolWhatsApp($nomor, $pesan = '', $label = 'WhatsApp') {
    $nomor = nomorWhatsApp($nomor);
    if (!$nomor) return '';
    return '<a class="btn btn-sm btn-outline-success mt-1 service-wa-button" target="_blank" rel="noopener noreferrer" href="https://wa.me/' . $nomor . '?text=' . rawurlencode($pesan) . '"><i class="fab fa-whatsapp me-1"></i> ' . layananEscape($label) . '</a>';
}
function statusKolektibilitas($hari) {
    $hari = max(0, (int) $hari);
    if ($hari <= 3) return ['Lancar', '#198754'];
    if ($hari < 7) return ['Terlambat', '#997404'];
    if ($hari < 30) return ['Macet Ringan', '#c65d00'];
    if ($hari <= 90) return ['Macet Berat', '#dc3545'];
    return ['Gagal Bayar', '#212529'];
}
function layananToken() {
    if (empty($_SESSION['layanan_csrf'])) $_SESSION['layanan_csrf'] = bin2hex(random_bytes(32));
    return '<input type="hidden" name="csrf" value="' . layananEscape($_SESSION['layanan_csrf']) . '">';
}
function layananValidasiToken() {
    $token=$_POST['csrf'] ?? '';
    if (!is_string($token) || empty($_SESSION['layanan_csrf']) || !hash_equals($_SESSION['layanan_csrf'], $token)) {
        throw new Exception('Sesi formulir tidak valid. Muat ulang halaman.');
    }
}
function sinkronkanProteksiUpload() {
    $root = realpath(__DIR__ . '/..') . '/uploads';
    $template = $root . '/anggota/.htaccess';
    if (!file_exists($template)) return;
    foreach (['jaminan_kembali', 'pembayaran', 'jaminan', 'pencairan'] as $sub) {
        $dir = $root . '/' . $sub;
        if (!is_dir($dir)) continue;
        $ht = $dir . '/.htaccess';
        if (!file_exists($ht)) {
            @copy($template, $ht);
        } else {
            $isi = @file_get_contents($ht);
            if ($isi !== false && strpos($isi, 'Require all denied') !== false && strpos($isi, 'FilesMatch') === false) {
                @copy($template, $ht);
            }
        }
    }
}

function layananSiap($db) {
    sinkronkanProteksiUpload();
    static $cache=[];$key=spl_object_hash($db);
    if (!array_key_exists($key,$cache)) $cache[$key]=kolomAda($db,'layanan_audit','aksi')
        && kolomAda($db,'layanan_pembayaran','dibatalkan_at')
        && kolomAda($db,'layanan_bayar_denda','denda_akhir')
        && kolomAda($db,'layanan_pengaturan','denda_jaminan_per_juta')
        && kolomAda($db,'layanan_pengaturan','bonus_persen')
        && kolomAda($db,'layanan_lelang','kredit')
        && kolomAda($db,'layanan_pembayaran','bunga_diskon')
        && kolomAda($db,'layanan_pengajuan_hapus_denda','aktif');
    return $cache[$key];
}
function layananSimpanFoto($foto, $folder) {
    if (!in_array($folder,['pembayaran','jaminan_kembali'],true)) throw new Exception('Folder bukti tidak valid.');
    $dir=__DIR__.'/../uploads/'.$folder;
    if (!is_dir($dir) && !mkdir($dir,0755,true)) throw new Exception('Folder bukti tidak dapat dibuat.');
    $htaccessFile = $dir.'/.htaccess';
    $root = realpath(__DIR__ . '/..') . '/uploads';
    if (file_exists($root . '/anggota/.htaccess')) {
        $kontenHt = @file_get_contents($htaccessFile);
        if (!$kontenHt || (strpos($kontenHt, 'Require all denied') !== false && strpos($kontenHt, 'FilesMatch') === false)) {
            @copy($root . '/anggota/.htaccess', $htaccessFile);
        }
    }
    return simpanFotoUpload($foto,$folder);
}
function layananPrioritasLayak($db,$anggota) {
    $s=$db->prepare("SELECT p.id, (SELECT COUNT(*) FROM angsuran a WHERE a.pinjaman_id=p.id AND (a.status<>'lunas' OR a.tanggal_bayar IS NULL OR DATE(a.tanggal_bayar)>a.tanggal_jatuh_tempo)) telat FROM pinjaman p WHERE p.anggota_id=? AND p.status='lunas' AND EXISTS(SELECT 1 FROM angsuran ax WHERE ax.pinjaman_id=p.id) ORDER BY p.tanggal_lunas DESC,p.id DESC LIMIT 2");
    $s->execute([$anggota]);$rows=$s->fetchAll(PDO::FETCH_ASSOC);
    return count($rows)===2 && !(int)$rows[0]['telat'] && !(int)$rows[1]['telat'];
}
function layananAudit($db, $pinjaman, $aksi, $detail) {
    $db->prepare('INSERT INTO layanan_audit (pinjaman_id,user_id,aksi,detail) VALUES (?,?,?,?)')
        ->execute([$pinjaman, $_SESSION['user_id'], $aksi, $detail]);
}

/** Jumlah pekerjaan pinjaman yang perlu terlihat tanpa membuka submenu. */
function layananHitungNotifikasi($db, $role, $userId = 0) {
    $hasil = [
        'pengajuan'=>0, 'persetujuan'=>0, 'pencairan'=>0, 'pembatalan'=>0,
        'hapus_denda'=>0, 'pelunasan_dini'=>0, 'jaminan_kembali'=>0,
        'prioritas'=>0, 'laporan_macet'=>0, 'pembayaran_denda'=>0, 'layanan'=>0, 'total'=>0,
        'pelunasan_disetujui'=>0, 'jaminan_disetujui'=>0,
        'jatuh_tempo'=>0, 'terlambat'=>0, 'denda_tertunggak'=>0,
        'anggota_pengajuan'=>0, 'anggota_pencairan'=>0, 'anggota_jatuh_tempo'=>0,
        'anggota_terlambat'=>0, 'anggota_denda'=>0, 'anggota_jaminan'=>0,
    ];
    if ($role === 'anggota') {
        $anggota=$db->prepare('SELECT id FROM anggota WHERE user_id=? LIMIT 1');
        $anggota->execute([(int)$userId]);$anggotaId=(int)$anggota->fetchColumn();
        if (!$anggotaId) return $hasil;
        $queries=[
            'anggota_pengajuan'=>"SELECT COUNT(*) FROM pengajuan_pinjaman WHERE anggota_id=? AND status='menunggu'",
            'anggota_pencairan'=>"SELECT COUNT(*) FROM pengajuan_pinjaman pp WHERE pp.anggota_id=? AND pp.status='disetujui' AND NOT EXISTS(SELECT 1 FROM pinjaman p WHERE p.pengajuan_id=pp.id)",
            'anggota_jatuh_tempo'=>"SELECT COUNT(*) FROM angsuran a JOIN pinjaman p ON p.id=a.pinjaman_id WHERE p.anggota_id=? AND p.status='aktif' AND a.status<>'lunas' AND a.tanggal_jatuh_tempo BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 7 DAY)",
            'anggota_terlambat'=>"SELECT COUNT(*) FROM angsuran a JOIN pinjaman p ON p.id=a.pinjaman_id WHERE p.anggota_id=? AND p.status='aktif' AND a.status<>'lunas' AND a.tanggal_jatuh_tempo<CURDATE()",
            'anggota_denda'=>"SELECT COUNT(*) FROM angsuran a JOIN pinjaman p ON p.id=a.pinjaman_id WHERE p.anggota_id=? AND a.status<>'lunas' AND a.denda>COALESCE((SELECT SUM(b.jumlah) FROM layanan_bayar_denda b WHERE b.angsuran_id=a.id),0)",
            'anggota_jaminan'=>"SELECT COUNT(*) FROM layanan_jaminan_kembali k JOIN pinjaman p ON p.id=k.pinjaman_id WHERE p.anggota_id=? AND k.status='disetujui'",
        ];
        foreach($queries as $key=>$sql){$stmt=$db->prepare($sql);$stmt->execute([$anggotaId]);$hasil[$key]=(int)$stmt->fetchColumn();}
        $hasil['detail'] = [
            'anggota_pengajuan' => (int)$db->query("SELECT id FROM pengajuan_pinjaman WHERE anggota_id=".(int)$anggotaId." AND status='menunggu' ORDER BY id DESC LIMIT 1")->fetchColumn(),
            'anggota_pencairan' => (int)$db->query("SELECT pp.id FROM pengajuan_pinjaman pp WHERE pp.anggota_id=".(int)$anggotaId." AND pp.status='disetujui' AND NOT EXISTS(SELECT 1 FROM pinjaman p WHERE p.pengajuan_id=pp.id) ORDER BY pp.id DESC LIMIT 1")->fetchColumn(),
        ];
        $hasil['total']=array_sum(array_intersect_key($hasil,$queries));
        return $hasil;
    }
    $hasil['pengajuan']=(int)$db->query("SELECT COUNT(*) FROM pengajuan_pinjaman WHERE status='menunggu'")->fetchColumn();
    $hasil['persetujuan']=$hasil['pengajuan'];
    $hasil['pencairan']=(int)$db->query("SELECT COUNT(*) FROM pengajuan_pinjaman pp WHERE pp.status='disetujui' AND NOT EXISTS(SELECT 1 FROM pinjaman p WHERE p.pengajuan_id=pp.id)")->fetchColumn();
    $hasil['jatuh_tempo']=(int)$db->query("SELECT COUNT(*) FROM angsuran a JOIN pinjaman p ON p.id=a.pinjaman_id WHERE p.status='aktif' AND a.status<>'lunas' AND a.tanggal_jatuh_tempo BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 7 DAY)")->fetchColumn();
    $hasil['terlambat']=(int)$db->query("SELECT COUNT(*) FROM angsuran a JOIN pinjaman p ON p.id=a.pinjaman_id WHERE p.status='aktif' AND a.status<>'lunas' AND a.tanggal_jatuh_tempo<CURDATE()")->fetchColumn();
    $hasil['denda_tertunggak']=(int)$db->query("SELECT COUNT(*) FROM angsuran a JOIN pinjaman p ON p.id=a.pinjaman_id WHERE p.status='aktif' AND a.status<>'lunas' AND a.denda>COALESCE((SELECT SUM(b.jumlah) FROM layanan_bayar_denda b WHERE b.angsuran_id=a.id),0)")->fetchColumn();
    if ($role === 'admin') {
        // Pencairan dan penagihan adalah tindakan Teller, sehingga tidak menjadi notifikasi Admin.
        $hasil['pencairan']=0;$hasil['jatuh_tempo']=0;$hasil['terlambat']=0;$hasil['denda_tertunggak']=0;
        $hasil['pembatalan']=function_exists('jumlahPengajuanPembatalan') ? (int)jumlahPengajuanPembatalan($db) : 0;
        foreach ([
            'hapus_denda'=>'layanan_pengajuan_hapus_denda',
            'pelunasan_dini'=>'layanan_pelunasan',
            'jaminan_kembali'=>'layanan_jaminan_kembali',
            'prioritas'=>'layanan_prioritas',
            'laporan_macet'=>'layanan_laporan_macet',
        ] as $key=>$table) {
            $hasil[$key]=(int)$db->query("SELECT COUNT(*) FROM $table WHERE status='menunggu'")->fetchColumn();
            $hasil['layanan']+=$hasil[$key];
        }
        $hasil['pembayaran_denda']=(int)$db->query('SELECT COUNT(*) FROM layanan_bayar_denda WHERE approved_by IS NULL')->fetchColumn();
        $hasil['layanan']+=$hasil['pembayaran_denda'];
        // Persetujuan adalah tampilan dari pengajuan yang sama, jadi jangan dihitung dua kali.
        $hasil['total']=$hasil['persetujuan']+$hasil['pembatalan']+$hasil['layanan'];
        $hasil['detail'] = [
            'persetujuan'=>(int)$db->query("SELECT id FROM pengajuan_pinjaman WHERE status='menunggu' ORDER BY id DESC LIMIT 1")->fetchColumn(),
            'pembatalan'=>(int)$db->query("SELECT x.pinjaman_id FROM pengajuan_pembatalan_angsuran pb JOIN angsuran x ON x.id=pb.angsuran_id WHERE pb.status='menunggu' ORDER BY pb.id DESC LIMIT 1")->fetchColumn(),
            'pembayaran_denda'=>(int)$db->query("SELECT pinjaman_id FROM layanan_bayar_denda WHERE approved_by IS NULL ORDER BY id DESC LIMIT 1")->fetchColumn(),
        ];
        foreach (['hapus_denda'=>'layanan_pengajuan_hapus_denda','pelunasan_dini'=>'layanan_pelunasan','jaminan_kembali'=>'layanan_jaminan_kembali','prioritas'=>'layanan_prioritas','laporan_macet'=>'layanan_laporan_macet'] as $key=>$table) {
            $hasil['detail'][$key]=(int)$db->query("SELECT pinjaman_id FROM $table WHERE status='menunggu' ORDER BY id DESC LIMIT 1")->fetchColumn();
        }
    } else {
        // Persetujuan pengajuan adalah tindakan Admin, bukan Teller.
        $hasil['pengajuan']=0;$hasil['persetujuan']=0;
        $keputusan=function_exists('jumlahKeputusanPembatalanTeller')
            ? jumlahKeputusanPembatalanTeller($db,(int)$userId) : ['disetujui'=>0,'ditolak'=>0];
        $hasil['pembatalan']=(int)($keputusan['disetujui']??0)+(int)($keputusan['ditolak']??0);
        $stmt=$db->prepare("SELECT COUNT(*) FROM layanan_pelunasan WHERE user_id=? AND status='disetujui'");$stmt->execute([(int)$userId]);$hasil['pelunasan_disetujui']=(int)$stmt->fetchColumn();
        $stmt=$db->prepare("SELECT COUNT(*) FROM layanan_jaminan_kembali WHERE user_id=? AND status='disetujui'");$stmt->execute([(int)$userId]);$hasil['jaminan_disetujui']=(int)$stmt->fetchColumn();
        $hasil['total']=$hasil['pencairan']+$hasil['pembatalan']+$hasil['pelunasan_disetujui']+$hasil['jaminan_disetujui']+$hasil['jatuh_tempo']+$hasil['terlambat']+$hasil['denda_tertunggak'];
        $hasil['detail'] = ['pencairan'=>(int)$db->query("SELECT pp.id FROM pengajuan_pinjaman pp WHERE pp.status='disetujui' AND NOT EXISTS(SELECT 1 FROM pinjaman p WHERE p.pengajuan_id=pp.id) ORDER BY pp.id DESC LIMIT 1")->fetchColumn()];
    }
    return $hasil;
}
function layananAdmin() {
    if (!isAdmin()) throw new Exception('Tindakan ini hanya untuk Admin.');
}
function layananNominal($value, $bolehNol = false) {
    if (!is_scalar($value) || !preg_match('/^\d{1,12}(\.\d{1,2})?$/D', (string) $value) || (!$bolehNol && (float) $value <= 0)) {
        throw new Exception('Nominal tidak valid. Gunakan angka positif tanpa pemisah ribuan.');
    }
    return round((float) $value, 2);
}
function layananPinjaman($db, $id) {
    $stmt = $db->prepare('SELECT p.*, jp.memerlukan_jaminan FROM pinjaman p JOIN jenis_pinjaman jp ON jp.id=p.jenis_pinjaman_id WHERE p.id=? FOR UPDATE');
    $stmt->execute([(int) $id]);
    $p = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$p) throw new Exception('Pinjaman tidak ditemukan.');
    return $p;
}
function layananSisa($db, $id, $diskon = 0) {
    $stmt = $db->prepare("SELECT COALESCE(SUM(pokok),0) pokok, COALESCE(SUM(bunga),0) bunga, COALESCE(SUM(denda),0) denda, COUNT(*) jumlah FROM angsuran WHERE pinjaman_id=? AND status<>'lunas'");
    $stmt->execute([$id]);
    $s = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt = $db->prepare("SELECT COALESCE(SUM(b.jumlah),0) FROM layanan_bayar_denda b JOIN angsuran a ON a.id=b.angsuran_id WHERE a.pinjaman_id=? AND a.status<>'lunas'");
    $stmt->execute([$id]);
    $s['denda_dibayar'] = (float) $stmt->fetchColumn();
    $s['diskon_bunga'] = round((float) $s['bunga'] * max(0, min(100, (float) $diskon)) / 100, 2);
    $s['kredit_lelang']=layananKreditLelang($db,$id);
    $s['sebelum_kredit']=round($s['pokok']+$s['bunga']-$s['diskon_bunga']+max(0,$s['denda']-$s['denda_dibayar']),2);
    $s['kredit_terpakai']=min($s['kredit_lelang'],$s['sebelum_kredit']);
    $s['total'] = max(0,round($s['sebelum_kredit']-$s['kredit_terpakai'],2));
    return $s;
}
// Kas dan transaksi selalu dicatat dalam transaksi DB milik pemanggil.
function layananCatatKas($db, $p, $jumlah, $keterangan) {
    $stmt = $db->query("SELECT id FROM jenis_simpanan WHERE kode_jenis='ANG'");
    $jenis = $stmt->fetchColumn();
    if (!$jenis) {
        $db->exec("INSERT INTO jenis_simpanan (kode_jenis,nama_jenis,deskripsi,status) VALUES ('ANG','Pembayaran Angsuran','Pembayaran pinjaman','aktif')");
        $jenis = $db->lastInsertId();
    }
    // Mutex transaksi kas, termasuk ketika tabel kas belum mempunyai baris.
    $db->query('SELECT id FROM koperasi WHERE id=1 FOR UPDATE')->fetchColumn();
    $saldo = (float) $db->query('SELECT saldo_sesudah FROM kas ORDER BY id DESC LIMIT 1 FOR UPDATE')->fetchColumn();
    $kode = 'LYN-' . bin2hex(random_bytes(8));
    $db->prepare("INSERT INTO transaksi (kode_transaksi,anggota_id,jenis_simpanan_id,jenis_transaksi,jumlah,saldo_sebelum,saldo_sesudah,keterangan,user_id,tanggal_transaksi) VALUES (?,?,?,'setoran',?,0,?,?,?,NOW())")
        ->execute([$kode,$p['anggota_id'],$jenis,$jumlah,$jumlah,$keterangan,$_SESSION['user_id']]);
    $id = $db->lastInsertId();
    $db->prepare("INSERT INTO kas (transaksi_id,jenis,jumlah,saldo_sebelum,saldo_sesudah,keterangan,tanggal) VALUES (?,'masuk',?,?,?,?,NOW())")
        ->execute([$id,$jumlah,$saldo,$saldo+$jumlah,$keterangan]);
    $stmt=$db->prepare('SELECT id FROM simpanan WHERE anggota_id=? AND jenis_simpanan_id=? FOR UPDATE');
    $stmt->execute([$p['anggota_id'],$jenis]);$sid=$stmt->fetchColumn();
    if ($sid) $db->prepare('UPDATE simpanan SET saldo=saldo+? WHERE id=?')->execute([$jumlah,$sid]);
    else $db->prepare('INSERT INTO simpanan (anggota_id,jenis_simpanan_id,saldo) VALUES (?,?,?)')->execute([$p['anggota_id'],$jenis,$jumlah]);
    return $id;
}
function layananDaftar($db) {
    return $db->query("SELECT p.*, ag.nama_lengkap,ag.kode_anggota,ag.ktp,ag.foto_ktp,ag.telepon,jp.memerlukan_jaminan,
        (SELECT COALESCE(SUM(ax.jumlah_angsuran),0) FROM angsuran ax WHERE ax.pinjaman_id=p.id) total_balik,
        COALESCE(s.sisa,0) sisa_tagihan, COALESCE(s.denda,0) sisa_denda, COALESCE(s.jumlah,0) sisa_cicilan,
        s.jatuh_tempo, s.terakhir, GREATEST(0,COALESCE(DATEDIFF(CURDATE(),s.jatuh_tempo),0)) hari_telat,
        j.nilai_taksiran,j.status_jaminan,j.foto_barang,j.foto_nasabah_barang,ro.pinjaman_id AS repeat_selesai
        FROM pinjaman p JOIN anggota ag ON ag.id=p.anggota_id JOIN jenis_pinjaman jp ON jp.id=p.jenis_pinjaman_id
        LEFT JOIN (SELECT pinjaman_id, SUM(jumlah_angsuran+denda) sisa,SUM(jumlah_angsuran) total_balik,SUM(denda) denda,COUNT(*) jumlah,
        MIN(tanggal_jatuh_tempo) jatuh_tempo,MAX(tanggal_jatuh_tempo) terakhir FROM angsuran WHERE status<>'lunas' GROUP BY pinjaman_id) s ON s.pinjaman_id=p.id
        LEFT JOIN layanan_repeat_order ro ON ro.pinjaman_id=p.id LEFT JOIN jaminan_pinjaman j ON j.pinjaman_id=p.id ORDER BY hari_telat DESC,p.id DESC")->fetchAll(PDO::FETCH_ASSOC);
}
