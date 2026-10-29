<?php
/** Mapper import final Excel. Satu file selalu atomik dan aman diulang. */
require_once __DIR__ . '/migrasi_excel_helper.php';
require_once __DIR__ . '/pinjaman_helper.php';
require_once __DIR__ . '/migrasi_dokumen_cadangan.php';
require_once __DIR__ . '/anggota_helper.php';

function migrasiImportDalamTransaksi(PDO $db, callable $mapper) {
    if ($db->inTransaction()) throw new RuntimeException('Import migrasi tidak dapat dimulai karena masih ada transaksi aktif.');
    $db->beginTransaction();
    try {
        $hasil = $mapper($db);
        // Hindari "There is no active transaction" bila DDL sempat memicu implicit commit.
        if ($db->inTransaction()) {
            $db->commit();
        }
        return $hasil;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}
function migrasiUang($v) { /* Label bunga gadai lama, mis. "15% + 10 + 10", tidak boleh dibaca sebagai 151010%. */ if((string)$v==='151010')return 15.66666667;if((string)$v==='15120')return 21; if(is_numeric($v)) return round((float)$v,2); $v=preg_replace('/[^0-9,.-]/','',(string)$v); if($v==='151010')return 15.66666667;if($v==='15120')return 21; if(substr_count($v,'.')>1)$v=str_replace('.','',$v); if(strpos($v,',')!==false&&strpos($v,'.')!==false)$v=str_replace(',','',$v); elseif(strpos($v,',')!==false)$v=str_replace(',','.',$v); elseif(preg_match('/\.\d{3}$/',$v))$v=str_replace('.','',$v); return is_numeric($v)?round((float)$v,2):0; }
function migrasiTanggal($v,$name='tanggal') { if(is_numeric($v)&&(float)$v>1000)return gmdate('Y-m-d',((int)$v-25569)*86400); $v=trim((string)$v); if($v==='')return null; $v=preg_replace('/^(Senin|Selasa|Rabu|Kamis|Jumat|Sabtu|Minggu),?\s*/iu','',$v); foreach(['Y-m-d','d/m/Y','d-m-Y'] as $f){$d=DateTime::createFromFormat('!'.$f,$v);if($d&&$d->format($f)===$v)return$d->format('Y-m-d');}throw new RuntimeException("Format $name tidak valid: $v"); }
function migrasiNorm($v){return strtolower(trim((string)$v));}
function migrasiStatus($v){return in_array(migrasiNorm($v),['nonaktif','0','tidak'],true)?'nonaktif':'aktif';}
function migrasiFreq($v){$v=migrasiNorm($v);return in_array($v,['bulanan','mingguan','dua_mingguan'],true)?$v:'bulanan';}
/** Samakan filter historis dengan pratinjau: hanya baris pembayaran aktual. */
function migrasiPembayaranAktual(array $r): bool {
    $status = migrasiNorm($r['Status*'] ?? '');
    $total = migrasiUang($r['Total Diterima (Rp)*'] ?? 0);
    $tanggal = trim((string)($r['Tanggal Bayar*'] ?? ''));
    return $total > 0.005 && $tanggal !== '' && ($status === '' || in_array($status, ['dibayar', 'lunas', 'pelunasan'], true));
}
/** Resolve anggota: mapping item dulu, fallback kode_anggota. */
function migrasiCariAnggota(PDO $db, string $kode): int {
    $kode = trim($kode);
    if ($kode === '') {
        return 0;
    }
    $id = migrasiItem($db, 'anggota', $kode);
    if ($id) {
        return $id;
    }
    $q = $db->prepare('SELECT id FROM anggota WHERE kode_anggota=? LIMIT 1');
    $q->execute([$kode]);
    $id = (int) $q->fetchColumn();
    if ($id > 0) {
        migrasiSaveItem($db, 'anggota', $kode, $id);
    }
    return $id;
}
function migrasiKey($p,$v){
    // Kolom kode transaksi/pengajuan maksimal 20 karakter. Sufiks hash
    // menghindari collision saat dua ID lama hanya berbeda di bagian akhir.
    $raw=preg_replace('/[^A-Za-z0-9_-]/','-',(string)$v);
    return substr($p.'-'.$raw,0,13).'-'.substr(hash('crc32b',$p.'|'.$v),0,6);
}
function migrasiTrimCell($nilai) { return trim((string) $nilai); }
function migrasiCellTerisi($nilai) { return trim((string) $nilai) !== ''; }
function migrasiRecords($path) { $all=[]; foreach(migrasiBacaXlsx($path) as $name=>$rows){$heads=array_map('migrasiTrimCell',isset($rows[2])?$rows[2]:[]);$all[$name]=[];foreach($rows as $no=>$row){if($no<3||!array_filter($row,'migrasiCellTerisi'))continue;$r=['_baris'=>$no];foreach($heads as $col=>$head)if($head!=='')$r[$head]=isset($row[$col])?$row[$col]:'';$all[$name][]=$r;}}return $all; }
function migrasiItem(PDO $db,$type,$key,$id=null){$q=$db->prepare('SELECT target_id FROM migrasi_import_item WHERE jenis=? AND source_key=?');$q->execute([$type,$key]);$old=$q->fetchColumn();if($old!==false)return(int)$old;if($id!==null){$q=$db->prepare('INSERT INTO migrasi_import_item(jenis,source_key,target_id) VALUES (?,?,?)');$q->execute([$type,$key,$id]);return(int)$id;}return 0;}
function migrasiSaveItem(PDO $db,$type,$key,$id){$q=$db->prepare('INSERT INTO migrasi_import_item(jenis,source_key,target_id) VALUES (?,?,?) ON DUPLICATE KEY UPDATE target_id=VALUES(target_id)');$q->execute([$type,$key,$id]);}
function migrasiKas(PDO $db,$key,$jenis,$jumlah,$tanggal,$ket,$transaksi=0){if(migrasiItem($db,'kas',$key))return;$before=(float)$db->query('SELECT COALESCE(saldo_sesudah,0) FROM kas ORDER BY id DESC LIMIT 1')->fetchColumn();$after=$before+($jenis==='masuk'?$jumlah:-$jumlah);$q=$db->prepare('INSERT INTO kas(transaksi_id,jenis,jumlah,saldo_sebelum,saldo_sesudah,keterangan,tanggal) VALUES(?,?,?,?,?,?,?)');$q->execute([$transaksi,$jenis,$jumlah,$before,$after,$ket,$tanggal.' 12:00:00']);migrasiSaveItem($db,'kas',$key,$db->lastInsertId());}
function migrasiRekalkulasiKas(PDO $db){$saldo=0;$rows=$db->query('SELECT id,jenis,jumlah FROM kas ORDER BY id FOR UPDATE')->fetchAll();$q=$db->prepare('UPDATE kas SET saldo_sebelum=?,saldo_sesudah=? WHERE id=?');foreach($rows as $r){$sebelum=$saldo;$saldo+=$r['jenis']==='masuk'?(float)$r['jumlah']:-(float)$r['jumlah'];$q->execute([$sebelum,$saldo,$r['id']]);}}
function migrasiAdmin(PDO $db,$id){if($id)return(int)$id;$id=$db->query("SELECT id FROM users WHERE role='admin' ORDER BY id LIMIT 1")->fetchColumn();if(!$id)throw new RuntimeException('Tidak ada Admin untuk mencatat import.');return(int)$id;}
function migrasiPastikanSkema(PDO $db){try{$db->query('SELECT 1 FROM migrasi_import_item LIMIT 1');}catch(Throwable $e){throw new RuntimeException('Jalankan database/migrations/MIGRASI_UNIVERSAL.sql terlebih dahulu.');}}
/** Path relatif aman di bawah uploads/ (tolak .. dan absolut). */
function migrasiPathUpload($path){
    $path=str_replace('\\','/',trim((string)$path));
    if($path===''||preg_match('#^(https?:)?//#i',$path)||preg_match('#^[a-zA-Z]:/#',$path))return '';
    if(strpos($path,'uploads/')===0)$path=substr($path,8);
    $path=ltrim($path,'/');
    if($path===''||strpos($path,'..')!==false)return '';
    // Legacy bare / assets path: simpan konsisten (basename atau assets/uploads/ktp/...)
    if(strpos($path,'assets/uploads/ktp/')===0){
        return $path;
    }
    return $path;
}
function migrasiImportKoperasi(PDO $db,array $data){
    $rows=$data['Konfigurasi_Koperasi']??[];
    if(!$rows)return 0;
    $r=$rows[0];
    $nama=trim((string)($r['Nama Koperasi*']??''));
    if($nama==='')return 0;
    $alamat=isset($r['Alamat'])?(string)$r['Alamat']:null;
    $telepon=isset($r['Telepon'])?(string)$r['Telepon']:null;
    $email=isset($r['Email'])?(string)$r['Email']:null;
    $rekening=isset($r['Rekening Bank'])?(string)$r['Rekening Bank']:null;
    $ada=$db->query('SELECT id FROM koperasi ORDER BY id LIMIT 1')->fetchColumn();
    if($ada){
        $db->prepare('UPDATE koperasi SET nama=?,alamat=?,telepon=?,email=?,rekening_bank=? WHERE id=?')
            ->execute([$nama,$alamat,$telepon,$email,$rekening,$ada]);
    }else{
        $db->prepare('INSERT INTO koperasi(nama,alamat,telepon,email,rekening_bank) VALUES(?,?,?,?,?)')
            ->execute([$nama,$alamat,$telepon,$email,$rekening]);
    }
    return 1;
}

function migrasiImportFinal(PDO $db,$path,$userId=null){
    migrasiPastikanSkema($db); migrasiPastikanCadanganDokumen($db); $data=migrasiRecords($path); $userId=migrasiAdmin($db,$userId);
    return migrasiImportDalamTransaksi($db,function(PDO $db)use($data,$userId){
        $n=array_fill_keys(['koperasi','anggota','produk','simpanan','denda','pinjaman','jadwal','pembayaran','kas','dapin','alokasi','dokumen'],0);
        $n['koperasi']=migrasiImportKoperasi($db,$data);
        foreach($data['Master_Simpanan']??[] as $r){$code=trim((string)$r['Kode Simpanan*']);if(!$code)continue;$q=$db->prepare('INSERT INTO jenis_simpanan(kode_jenis,nama_jenis,minimum,maksimum,bagi_hasil,status,deskripsi) VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE nama_jenis=VALUES(nama_jenis),minimum=VALUES(minimum),maksimum=VALUES(maksimum),bagi_hasil=VALUES(bagi_hasil),status=VALUES(status),deskripsi=VALUES(deskripsi)');$q->execute([$code,$r['Nama Simpanan*'],migrasiUang($r['Minimum (Rp)']??0),migrasiUang($r['Maksimum (Rp)']??0),migrasiUang($r['Bagi Hasil (%)']??0),migrasiStatus($r['Status*']),$r['Deskripsi']??null]);$n['simpanan']++;}
        foreach($data['Master_Produk']??[] as $r){$code=trim((string)$r['Kode Produk*']);if(!$code)continue;$f=migrasiFreq($r['Frekuensi*']);$q=$db->prepare('INSERT INTO jenis_pinjaman(kode_jenis,nama_jenis,maksimal_pinjaman,tenor_max,bunga,frekuensi_diizinkan,memerlukan_jaminan,status,deskripsi) VALUES(?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE nama_jenis=VALUES(nama_jenis),maksimal_pinjaman=VALUES(maksimal_pinjaman),tenor_max=VALUES(tenor_max),bunga=VALUES(bunga),frekuensi_diizinkan=VALUES(frekuensi_diizinkan),memerlukan_jaminan=VALUES(memerlukan_jaminan),status=VALUES(status),deskripsi=VALUES(deskripsi)');$q->execute([$code,$r['Nama Produk*'],migrasiUang($r['Plafon Maks. (Rp)*']),max(1,(int)$r['Tenor Maks. (Bulan)*']),migrasiUang($r['Bunga Nasabah (%)*']),$f,in_array(migrasiNorm($r['Perlu Jaminan?*']),['ya','1'],true),migrasiStatus($r['Status*']),$r['Deskripsi']??null]);$n['produk']++;}
        foreach($data['Master_Denda']??[] as $r){$p=migrasiUang($r['Plafon Pinjaman (Rp)*']);if(!$p)continue;$q=$db->prepare('INSERT INTO denda_tarif(jumlah_pinjaman,denda_per_hari) VALUES(?,?) ON DUPLICATE KEY UPDATE denda_per_hari=VALUES(denda_per_hari)');$q->execute([$p,migrasiUang($r['Denda per Hari (Rp)*'])]);$n['denda']++;}
        foreach($data['Nasabah']??[] as $r){
            $old=trim((string)$r['ID Nasabah Lama*']); if($old==='') continue;
            $q=$db->prepare('SELECT id,foto_ktp FROM anggota WHERE kode_anggota=?');
            $q->execute([$old]); $rowAnggota=$q->fetch(PDO::FETCH_ASSOC)?:null; $id=$rowAnggota?$rowAnggota['id']:null;
            $foto=migrasiPathUpload($r['Foto']??$r['Foto KTP']??$r['Foto KTP (opsional)']??'');
            $fotoKk=migrasiPathUpload($r['Foto KK']??$r['Foto KK (opsional)']??'');
            $fotoPasangan=migrasiPathUpload($r['Foto KTP Pasangan']??$r['Foto KTP Pasangan (opsional)']??'');
            $ttd=trim((string)($r['TTD']??$r['Tanda Tangan']??$r['TTD (opsional)']??''));
            if($ttd!=='' && strpos($ttd,'data:')!==0){
                // Path wajib lolos sanitasi; jangan simpan nilai mentah dari Excel.
                $ttd=migrasiPathUpload($ttd);
            }
            $vals=[trim((string)$r['Nama Lengkap*']),trim((string)$r['NIK*']),isset($r['Alamat*'])?$r['Alamat*']:null,isset($r['No. HP'])?$r['No. HP']:null,migrasiStatus(isset($r['Status'])?$r['Status']:'aktif')];
            if($id){
                $sql='UPDATE anggota SET nama_lengkap=?,ktp=?,alamat=?,telepon=?,status=?,foto_ktp=COALESCE(NULLIF(?,\'\'),foto_ktp)';
                $params=array_merge($vals,[$foto]);
                if(kolomAda($db,'anggota','foto_kk')){ $sql.=',foto_kk=COALESCE(NULLIF(?,\'\'),foto_kk)'; $params[]=$fotoKk; }
                if(kolomAda($db,'anggota','foto_ktp_pasangan')){ $sql.=',foto_ktp_pasangan=COALESCE(NULLIF(?,\'\'),foto_ktp_pasangan)'; $params[]=$fotoPasangan; }
                $sql.=' WHERE id=?'; $params[]=$id;
                $db->prepare($sql)->execute($params);
            }else{
                $cols='kode_anggota,nama_lengkap,ktp,alamat,telepon,status,foto_ktp'; $ph='?,?,?,?,?,?,?';
                $ins=array_merge([$old],$vals,[($foto!==''?$foto:null)]);
                if(kolomAda($db,'anggota','foto_kk')){ $cols.=',foto_kk'; $ph.=',?'; $ins[]=($fotoKk!==''?$fotoKk:null); }
                if(kolomAda($db,'anggota','foto_ktp_pasangan')){ $cols.=',foto_ktp_pasangan'; $ph.=',?'; $ins[]=($fotoPasangan!==''?$fotoPasangan:null); }
                $db->prepare("INSERT INTO anggota($cols,tanggal_daftar) VALUES($ph,CURDATE())")->execute($ins);
                $id=$db->lastInsertId();
            }
            $fotoSimpan=$foto!==''?$foto:(($rowAnggota['foto_ktp']??'')?:null);
            $db->prepare('INSERT INTO migrasi_anggota_dokumen(anggota_id,foto,ttd) VALUES(?,?,?) ON DUPLICATE KEY UPDATE foto=COALESCE(NULLIF(VALUES(foto),\'\'),foto),ttd=COALESCE(NULLIF(VALUES(ttd),\'\'),ttd)')
                ->execute([$id,$fotoSimpan,($ttd!==''?$ttd:null)]);
            // Pulihkan foto/TTD dari cadangan reset bila Excel tidak mengisi path.
            $n['dokumen'] += migrasiPulihkanDokumenAnggota($db, $id, $old);
            // Pastikan akun login tersedia (kecuali status anggota nonaktif).
            pastikanAkunLoginAnggota($db, (int) $id);
            migrasiSaveItem($db,'anggota',$old,$id); $n['anggota']++;
        }
        foreach($data['Pendanaan_Dapin']??[] as $r){$code=trim((string)$r['ID Dapin*']);if(!$code)continue;$q=$db->prepare('INSERT INTO pendanaan_dapin(kode_dapin,nama_pendana,tanggal_diterima,modal_diterima,tenor,total_wajib_kembali,tanggal_jatuh_tempo,status,catatan) VALUES(?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE nama_pendana=VALUES(nama_pendana),tanggal_diterima=VALUES(tanggal_diterima),modal_diterima=VALUES(modal_diterima),tenor=VALUES(tenor),total_wajib_kembali=VALUES(total_wajib_kembali),tanggal_jatuh_tempo=VALUES(tanggal_jatuh_tempo),status=VALUES(status),catatan=VALUES(catatan)');$q->execute([$code,$r['Nama/Lembaga Dapin*'],migrasiTanggal($r['Tanggal Dana Diterima*'],'tanggal Dapin'),migrasiUang($r['Modal Diterima (Rp)*']),max(1,(int)$r['Tenor (Bulan)*']),migrasiUang($r['Total Wajib Kembali (Rp)*']),migrasiTanggal($r['Tanggal Jatuh Tempo']??''),migrasiStatus($r['Status*']),$r['Catatan']??null]);$q=$db->prepare('SELECT id FROM pendanaan_dapin WHERE kode_dapin=?');$q->execute([$code]);migrasiSaveItem($db,'dapin',$code,$q->fetchColumn());$n['dapin']++;}
        foreach($data['Pinjaman']??[] as $r){$code=trim((string)$r['ID Pinjaman Lama*']);if(!$code)continue;$member=migrasiCariAnggota($db,trim((string)$r['ID Nasabah Lama*']));$q=$db->prepare('SELECT id FROM jenis_pinjaman WHERE kode_jenis=?');$q->execute([trim((string)$r['Kode Produk*'])]);$product=$q->fetchColumn();if(!$member||!$product)throw new RuntimeException("Relasi untuk pinjaman $code tidak ditemukan.");$date=migrasiTanggal($r['Tanggal Cair Asli*'],'tanggal cair');$pokok=migrasiUang($r['Plafon (Rp)*']);$tenor=max(1,(int)$r['Tenor (Bulan)*']);$freq=migrasiFreq($r['Frekuensi*']);$bunga=migrasiUang(preg_replace('/[^0-9,.]/','',(string)$r['Bunga (%)*']));$skema=migrasiNorm($r['Skema Bunga*'])==='tahunan'?'tahunan':'flat';$hitung=hitungPinjaman($pokok,$bunga,$tenor,$freq,$skema);$q=$db->prepare('SELECT id FROM pinjaman WHERE kode_pinjaman=?');$q->execute([$code]);$id=$q->fetchColumn();if(!$id){$q=$db->prepare("INSERT INTO pengajuan_pinjaman(kode_pengajuan,anggota_id,jenis_pinjaman_id,jumlah_pinjaman,tenor,bunga,skema_bunga,frekuensi,tujuan_pinjaman,status,admin_id,user_id,tanggal_pengajuan,tanggal_persetujuan) VALUES(?,?,?,?,?,?,?,?,?,'dicairkan',?,?,?,?)");$q->execute([migrasiKey('MIG',$code),$member,$product,$pokok,$tenor,$bunga,$skema,$freq,'Migrasi '.$code,$userId,$userId,$date.' 12:00:00',$date.' 12:00:00']);$pengajuan=$db->lastInsertId();$total=migrasiUang($r['Total Tagihan (Rp)*'])?:$hitung['total'];$q=$db->prepare('INSERT INTO pinjaman(pengajuan_id,kode_pinjaman,anggota_id,jenis_pinjaman_id,jumlah_pinjaman,sisa_pokok,tenor,bunga,skema_bunga,frekuensi,jumlah_cicilan,total_pinjaman,angsuran_per_bulan,tanggal_pencairan,jatuh_tempo,status) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,\'aktif\')');$q->execute([$pengajuan,$code,$member,$product,$pokok,$pokok,$tenor,$bunga,$skema,$freq,$hitung['jumlah_cicilan'],$total,migrasiUang($r['Angsuran Normal (Rp)*'])?:$hitung['angsuran'],$date,hitungJatuhTempo($date,$hitung['jumlah_cicilan'],$freq)]);$id=$db->lastInsertId();foreach($hitung['rincian'] as $a){$q=$db->prepare("INSERT INTO angsuran(pinjaman_id,angsuran_ke,jumlah_angsuran,pokok,bunga,tanggal_jatuh_tempo,status) VALUES(?,?,?,?,?,?, 'belum_bayar')");$q->execute([$id,$a['ke'],$a['jumlah'],$a['pokok'],$a['bunga'],hitungJatuhTempo($date,$a['ke'],$freq)]);$n['jadwal']++;}migrasiKas($db,'cair-'.$code,'keluar',$pokok,$date,'Pencairan migrasi '.$code);}
            $id=(int)$id;
            // Re-import / data lama: pastikan jadwal cicilan ada supaya bisa diproses di Angsuran.
            $cntAng=$db->prepare('SELECT COUNT(*) FROM angsuran WHERE pinjaman_id=?');
            $cntAng->execute([$id]);
            if ((int)$cntAng->fetchColumn() === 0) {
                foreach ($hitung['rincian'] as $a) {
                    $q=$db->prepare("INSERT INTO angsuran(pinjaman_id,angsuran_ke,jumlah_angsuran,pokok,bunga,tanggal_jatuh_tempo,status) VALUES(?,?,?,?,?,?, 'belum_bayar')");
                    $q->execute([$id,$a['ke'],$a['jumlah'],$a['pokok'],$a['bunga'],hitungJatuhTempo($date,$a['ke'],$freq)]);
                    $n['jadwal']++;
                }
            }
            $fotoCair=migrasiPathUpload($r['Foto Pencairan']??$r['Foto Pencairan (opsional)']??'');if($fotoCair!==''&&kolomAda($db,'pinjaman','foto_pencairan')){$db->prepare('UPDATE pinjaman SET foto_pencairan=COALESCE(NULLIF(?,\'\'),foto_pencairan) WHERE id=?')->execute([$fotoCair,$id]);}$n['dokumen']+=migrasiPulihkanDokumenPinjaman($db,$id,$code);migrasiSaveItem($db,'pinjaman',$code,$id);$n['pinjaman']++;}
        $ang=$db->query("SELECT id FROM jenis_simpanan WHERE kode_jenis='ANG' LIMIT 1")->fetchColumn();if(!$ang)throw new RuntimeException('Jenis simpanan ANG wajib tersedia.');
        foreach($data['Angsuran_Historis']??[] as $r){
            $key=trim((string)$r['ID Pembayaran']); if($key==='') continue;
            // Samakan dengan pratinjau: skip draft / belum dibayar / total 0.
            if (!migrasiPembayaranAktual($r)) continue;
            if(migrasiItem($db,'pembayaran',$key)) continue;
            // Guard kedua: jika mapping item hilang tetapi baris pembayaran sudah ada, jangan dobel.
            $cekBayar=$db->prepare('SELECT transaksi_id FROM migrasi_pembayaran WHERE source_key=?');
            $cekBayar->execute([$key]);
            $transExisting=$cekBayar->fetchColumn();
            if($transExisting!==false){ migrasiSaveItem($db,'pembayaran',$key,(int)$transExisting); continue; }
            $loan=migrasiItem($db,'pinjaman',trim((string)$r['ID Pinjaman Lama*']));
            if(!$loan){
                $qLoan=$db->prepare('SELECT id FROM pinjaman WHERE kode_pinjaman=? LIMIT 1');
                $qLoan->execute([trim((string)$r['ID Pinjaman Lama*'])]);
                $loan=(int)$qLoan->fetchColumn();
                if($loan) migrasiSaveItem($db,'pinjaman',trim((string)$r['ID Pinjaman Lama*']),$loan);
            }
            if(!$loan) throw new RuntimeException("Pinjaman pembayaran $key tidak ditemukan.");
            $date=migrasiTanggal($r['Tanggal Bayar*'],'tanggal bayar');
            $total=migrasiUang($r['Total Diterima (Rp)*']);
            $jenis=migrasiNorm($r['Jenis Pembayaran*'])==='pelunasan'?'pelunasan':'cicilan';
            $keRaw=trim((string)($r['Angsuran Ke']??''));
            $ke=max(1,(int)$keRaw);
            // Pelunasan: lunasi SEMUA sisa cicilan (Angsuran Ke kosong di template = OK).
            $lunasiSemua=$jenis==='pelunasan';
            if ($lunasiSemua) {
                $qMax=$db->prepare("SELECT COALESCE(MAX(angsuran_ke),1) FROM angsuran WHERE pinjaman_id=? AND status<>'lunas'");
                $qMax->execute([$loan]);
                $ke=max($ke,(int)$qMax->fetchColumn());
            }
            $q=$db->prepare('SELECT anggota_id,kode_pinjaman FROM pinjaman WHERE id=?'); $q->execute([$loan]); $p=$q->fetch();
            $q=$db->prepare("INSERT INTO transaksi(kode_transaksi,anggota_id,jenis_simpanan_id,jenis_transaksi,jumlah,saldo_sebelum,saldo_sesudah,keterangan,user_id,tanggal_transaksi) VALUES(?,?,?,'setoran',?,0,?,?,?,?)");
            $q->execute([migrasiKey('BYR',$key),$p['anggota_id'],$ang,$total,$total,'Pembayaran migrasi '.$key.' - '.$p['kode_pinjaman'],$userId,$date.' 12:00:00']);
            $trans=$db->lastInsertId();
            $denda=migrasiUang($r['Denda (Rp)']??0);
            $q=$db->prepare('INSERT INTO migrasi_pembayaran(source_key,pinjaman_id,transaksi_id,tanggal_bayar,angsuran_ke,jenis_pembayaran,pokok,bunga,denda,total_diterima,status,keterangan) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
            $q->execute([$key,$loan,$trans,$date,$ke,$jenis,migrasiUang($r['Pokok (Rp)']??0),migrasiUang($r['Bunga (Rp)']??0),$denda,$total,migrasiNorm($r['Status*']),$r['Keterangan']??null]);
            migrasiSaveItem($db,'pembayaran',$key,$trans);
            if ($lunasiSemua) {
                $q=$db->prepare("UPDATE angsuran SET status='lunas',tanggal_bayar=?,cara_bayar='pelunasan',denda=CASE WHEN angsuran_ke=? THEN ? ELSE denda END,denda_dibayar=CASE WHEN angsuran_ke=? THEN GREATEST(COALESCE(denda_dibayar,0),?) ELSE denda_dibayar END WHERE pinjaman_id=? AND status<>'lunas'");
                $q->execute([$date,$ke,$denda,$ke,$denda,$loan]);
            } else {
                $q=$db->prepare("UPDATE angsuran SET status='lunas',tanggal_bayar=?,denda=CASE WHEN angsuran_ke=? THEN ? ELSE denda END,denda_dibayar=CASE WHEN angsuran_ke=? THEN GREATEST(COALESCE(denda_dibayar,0),?) ELSE denda_dibayar END,cara_bayar=? WHERE pinjaman_id=? AND angsuran_ke<=? AND status<>'lunas'");
                $q->execute([$date,$ke,$denda,$ke,$denda,$jenis,$loan,$ke]);
            }
            // Laporan Denda membaca layanan_bayar_denda — isi saat historis punya kolom Denda.
            if ($denda > 0.005) {
                try {
                    $angQ = $db->prepare('SELECT id, denda FROM angsuran WHERE pinjaman_id=? AND angsuran_ke=? LIMIT 1');
                    $angQ->execute([$loan, $ke]);
                    $angRow = $angQ->fetch(PDO::FETCH_ASSOC);
                    if ($angRow) {
                        $cekDenda = $db->prepare('SELECT id FROM layanan_bayar_denda WHERE angsuran_id=? AND transaksi_id=? LIMIT 1');
                        $cekDenda->execute([(int) $angRow['id'], (int) $trans]);
                        if (!$cekDenda->fetchColumn()) {
                            $insDenda = $db->prepare(
                                'INSERT INTO layanan_bayar_denda
                                 (angsuran_id,pinjaman_id,user_id,jumlah,metode,bukti,transaksi_id,approved_by,created_at,hari_telat,tarif,denda_awal,denda_akhir)
                                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
                            );
                            $insDenda->execute([
                                (int) $angRow['id'],
                                (int) $loan,
                                (int) $userId,
                                $denda,
                                'tunai',
                                'migrasi',
                                (int) $trans,
                                (int) $userId,
                                $date . ' 12:00:00',
                                0,
                                0,
                                $denda,
                                $denda,
                            ]);
                        }
                    }
                } catch (Throwable $e) {
                    // Hanya toleransi skema lama (kolom snapshot belum ada).
                    $msg = $e->getMessage();
                    if (stripos($msg, 'Unknown column') === false && stripos($msg, 'no such column') === false) {
                        throw $e;
                    }
                    error_log('migrasi layanan_bayar_denda dilewati (skema lama): ' . $msg);
                }
            }
            migrasiKas($db,'bayar-'.$key,'masuk',$total,$date,'Pembayaran migrasi '.$key.' - '.$p['kode_pinjaman'].($denda?' (denda)':''),$trans);
            $n['pembayaran']++;
        }
        foreach($data['Alokasi_Dapin']??[] as $r){$key=trim((string)($r['ID Alokasi']??''))?:trim((string)$r['ID Dapin*']).'|'.trim((string)$r['ID Pinjaman Lama*']);$loan=migrasiItem($db,'pinjaman',trim((string)$r['ID Pinjaman Lama*']));$dapin=migrasiItem($db,'dapin',trim((string)$r['ID Dapin*']));if(!$loan||!$dapin)throw new RuntimeException("Alokasi Dapin $key tidak valid.");$tglAlokasi=$r['Tanggal Alokasi*']??$r['Tanggal Alokasi (opsional)']??'';$q=$db->prepare('INSERT INTO alokasi_dapin(source_key,dapin_id,pinjaman_id,modal_dialokasikan,kewajiban_dapin,tanggal_alokasi,catatan) VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE modal_dialokasikan=VALUES(modal_dialokasikan),kewajiban_dapin=VALUES(kewajiban_dapin),tanggal_alokasi=VALUES(tanggal_alokasi),catatan=VALUES(catatan)');$q->execute([$key,$dapin,$loan,migrasiUang($r['Modal Dialokasikan (Rp)*']),migrasiUang($r['Kewajiban Dapin (Rp)*']),$tglAlokasi!==''?migrasiTanggal($tglAlokasi,'tanggal alokasi'):null,$r['Catatan']??null]);$n['alokasi']++;}
        foreach($data['Saldo_Awal_Kas']??[] as $r){$date=migrasiTanggal($r['Tanggal Cut-off*'],'tanggal cut-off');$amount=migrasiUang($r['Saldo Kas Fisik (Rp)*']);if($amount)migrasiKas($db,'saldo-awal-'.$date,'masuk',$amount,$date,'Saldo awal kas migrasi: '.($r['Dasar/Perhitungan Saldo*']??''));$n['kas']++;}
        foreach($data['Pinjaman']??[] as $r){
            $kodePinjaman=trim((string)$r['ID Pinjaman Lama*']);
            $id=migrasiItem($db,'pinjaman',$kodePinjaman);
            if(!$id){
                $qFind=$db->prepare('SELECT id FROM pinjaman WHERE kode_pinjaman=? LIMIT 1');
                $qFind->execute([$kodePinjaman]);
                $id=(int)$qFind->fetchColumn();
                if($id) migrasiSaveItem($db,'pinjaman',$kodePinjaman,$id);
            }
            if(!$id)continue;
            $q=$db->prepare("SELECT COUNT(*),COALESCE(SUM(pokok),0) FROM angsuran WHERE pinjaman_id=? AND status<>'lunas'");
            $q->execute([$id]);
            $hasilSisa=$q->fetch(PDO::FETCH_NUM);
            $left=(int)$hasilSisa[0];
            $sisa=(float)$hasilSisa[1];
            // Jangan paksa lunas dari Excel bila masih ada cicilan belum bayar.
            $lunas=$left===0;
            if($lunas){
                $sisa=0.0;
                $tglLunasRaw=trim((string)($r['Tanggal Lunas']??''));
                $date=$tglLunasRaw!==''?migrasiTanggal($tglLunasRaw,'tanggal lunas'):null;
                if($date===null){
                    $qTgl=$db->prepare("SELECT MAX(tanggal_bayar) FROM angsuran WHERE pinjaman_id=? AND status='lunas'");
                    $qTgl->execute([$id]);
                    $date=$qTgl->fetchColumn()?:null;
                }
            }else{
                $date=null;
            }
            $statusPinjaman=$lunas?'lunas':'aktif';
            $db->prepare('UPDATE pinjaman SET sisa_pokok=?,status=?,tanggal_lunas=? WHERE id=?')->execute([$sisa,$statusPinjaman,$date,$id]);
        }
        foreach($data['Pendanaan_Dapin']??[] as $r){$code=trim((string)$r['ID Dapin*']);if($code==='')continue;migrasiKas($db,'dapin-'.$code,'masuk',migrasiUang($r['Modal Diterima (Rp)*']),migrasiTanggal($r['Tanggal Dana Diterima*'],'tanggal Dapin'),'Modal pendanaan Dapin '.$code);}
        // Status nasabah mengikuti sheet Nasabah (jangan timpa nonaktif yang disengaja).
        // Setelah Reset Total, isi Status=aktif di Excel untuk nasabah yang masih beroperasi.
        migrasiRekalkulasiKas($db);
        return $n;
    });
}
