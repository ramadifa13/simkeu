<?php
/**
 * Single implementation — Pencairan Pinjaman (Admin & Teller).
 * Entry: admin|teller/pencairan.php (thin wrappers).
 * Base: richer teller flow (foto + akad). Keputusan TC: admin only.
 */
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/auth.php';

if (!isLoggedIn()) {
    header('Location: ../login.php');
    exit;
}
if (!isAdmin() && !isTeller()) {
    http_response_code(403);
    exit('Akses ditolak.');
}

$page_title = "Status Pencairan Pinjaman";
$bolehCairkan = can('pencairan.cairkan');
$bolehKeputusanTc = can('pencairan.keputusan_tc');

// PERBAIKAN: Ambil data koperasi dari database
$koperasi_query = "SELECT * FROM koperasi WHERE id = 1";
$koperasi_stmt = $db->prepare($koperasi_query);
$koperasi_stmt->execute();
$koperasi = $koperasi_stmt->fetch(PDO::FETCH_ASSOC);

// Jika tidak ada data koperasi, buat default
if (!$koperasi) {
    $koperasi = [
        'nama' => 'Koperasi Simpan Pinjam Sejahtera',
        'alamat' => 'Jl. Belum Diatur',
        'telepon' => '-',
        'email' => '-',
        'rekening_bank' => '-',
        'logo' => null
    ];
}
$aturan_perjanjian_form = pengaturanPerjanjian($db);

// Upload melebihi post_max_size membuat $_POST kosong; tampilkan pesan yang jelas
cekPostMelebihiBatas();

// Keputusan TC: persetujuan = batal cair; penolakan membuka kembali pengajuan.
if ($_POST && isset($_POST['keputusan_tc'])) {
    if (!$bolehKeputusanTc) {
        http_response_code(403);
        exit('Akses ditolak. Hanya Admin yang dapat memutuskan TC.');
    }
    try {
        layananValidasiToken();
        $tcId=(int)($_POST['tc_id'] ?? 0); $aksi=$_POST['keputusan_tc']; $catatan=trim((string)($_POST['catatan_tc'] ?? ''));
        if (!in_array($aksi,['disetujui','ditolak'],true)) throw new Exception('Keputusan TC tidak valid.');
        if (strlen($catatan)<5 || strlen($catatan)>2000) throw new Exception('Keterangan Admin wajib 5–2000 karakter.');
        $db->beginTransaction();
        $stmt=$db->prepare("UPDATE layanan_tunda_cair SET status=?,admin_id=?,catatan_admin=?,diputuskan_at=NOW() WHERE id=? AND status='menunggu'");
        $stmt->execute([$aksi,$_SESSION['user_id'],$catatan,$tcId]);
        if(!$stmt->rowCount()) throw new Exception('Pengajuan TC tidak ditemukan atau sudah diputuskan.');
        if ($aksi === 'disetujui') {
            $batal=$db->prepare("UPDATE pengajuan_pinjaman pp JOIN layanan_tunda_cair tc ON tc.pengajuan_id=pp.id SET pp.status='batal_cair', pp.catatan_admin=CONCAT(COALESCE(pp.catatan_admin,''), '\nBatal cair: ', tc.catatan_admin), pp.admin_id=?, pp.tanggal_persetujuan=NOW() WHERE tc.id=? AND NOT EXISTS (SELECT 1 FROM pinjaman p WHERE p.pengajuan_id=pp.id)");
            $batal->execute([$_SESSION['user_id'],$tcId]);
            if (!$batal->rowCount()) throw new Exception('Pengajuan tidak dapat dibatalkan karena sudah dicairkan.');
            $anggotaTc=$db->prepare("SELECT pp.anggota_id FROM pengajuan_pinjaman pp JOIN layanan_tunda_cair tc ON tc.pengajuan_id=pp.id WHERE tc.id=?");
            $anggotaTc->execute([$tcId]);
            $anggotaTcId=(int)$anggotaTc->fetchColumn();
            if ($anggotaTcId > 0) {
                bersihkanKodeAnggotaJaminanJikaTidakCair($db, $anggotaTcId);
            }
            $_SESSION['success']='TC disetujui. Pengajuan ditandai Batal Cair dan nasabah dapat mengajukan kembali.';
        } else {
            $_SESSION['success']='TC ditolak. Pengajuan lama kembali dapat diproses untuk pencairan.';
        }
        $db->commit();
    } catch(Exception $e) { if ($db->inTransaction()) $db->rollBack(); $_SESSION['error']='Gagal menyimpan keputusan TC: '.$e->getMessage(); }
    header('Location: pencairan.php'); exit;
}

// Sinkronisasi status pengajuan ↔ pinjaman (admin maintenance)
if (isset($_GET['sync'])) {
    if (!isAdmin()) {
        http_response_code(403);
        exit('Akses ditolak.');
    }
    try {
        $db->beginTransaction();
        $query_sync = "UPDATE pengajuan_pinjaman pp
                       JOIN pinjaman p ON pp.id = p.pengajuan_id
                       SET pp.status = 'dicairkan'
                       WHERE pp.status != 'dicairkan'";
        $stmt_sync = $db->prepare($query_sync);
        $stmt_sync->execute();
        $affected = $stmt_sync->rowCount();
        $db->commit();
        $_SESSION['success'] = "Sinkronisasi data berhasil. $affected data diupdate.";
        header("Location: pencairan.php");
        exit();
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $_SESSION['error'] = "Gagal sinkronisasi: " . $e->getMessage();
    }
}

// Tunda cair (TC) — staff dapat mengajukan; Admin memutuskan di atas.
if ($_POST && isset($_POST['ajukan_tc'])) {
    try {
        layananValidasiToken();
        $id=(int)($_POST['id'] ?? 0); $alasan=trim((string)($_POST['alasan_tc'] ?? ''));
        if (strlen($alasan)<5 || strlen($alasan)>2000) throw new Exception('Keterangan TC wajib 5–2000 karakter.');
        $stmt=$db->prepare("SELECT id FROM pengajuan_pinjaman WHERE id=? AND status='disetujui' AND NOT EXISTS (SELECT 1 FROM pinjaman WHERE pengajuan_id=pengajuan_pinjaman.id) FOR UPDATE");
        $db->beginTransaction(); $stmt->execute([$id]);
        if (!$stmt->fetchColumn()) throw new Exception('Pengajuan tidak tersedia untuk TC.');
        $cekTc=$db->prepare("SELECT status FROM layanan_tunda_cair WHERE pengajuan_id=? FOR UPDATE");
        $cekTc->execute([$id]);
        if ($cekTc->fetchColumn() !== false) throw new Exception('TC sudah pernah diputuskan Admin dan tidak dapat diajukan ulang.');
        $db->prepare("INSERT INTO layanan_tunda_cair (pengajuan_id,user_id,status,alasan) VALUES (?,?, 'menunggu', ?)")
           ->execute([$id,$_SESSION['user_id'],$alasan]);
        $db->commit(); $_SESSION['success']='Pengajuan TC dikirim dan menunggu persetujuan Admin.';
    } catch(Exception $e) { if($db->inTransaction())$db->rollBack(); $_SESSION['error']='Gagal mengajukan TC: '.$e->getMessage(); }
    header('Location: pencairan.php'); exit;
}

// ============================================
// PROSES PENCAIRAN (staff dengan pencairan.cairkan, foto nasabah wajib)
// Persetujuan Admin hanya mengubah status; pinjaman + jadwal angsuran dibuat di sini,
// jatuh tempo dihitung dari tanggal pencairan (hari ini).
// ============================================
if ($_POST && isset($_POST['proses_pencairan'])) {
    if (!$bolehCairkan) {
        http_response_code(403);
        exit('Akses ditolak.');
    }
    $id = (int) $_POST['id'];
    $foto_path = null;
    $foto_barang_path = null;
    $foto_nasabah_barang_path = null;
    $lampiran_paths = [];
    $committed = false;
    $errmode = $db->getAttribute(PDO::ATTR_ERRMODE);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    try {
        $db->beginTransaction();

        $stmt = $db->prepare("SELECT pp.*, jp.memerlukan_jaminan, jp.nama_jenis, j.id AS jaminan_id, j.jenis_barang,
                                     j.identitas_barang, j.deskripsi_barang, a.nama_lengkap, a.kode_anggota, a.kode_anggota_jaminan,
                                     a.ktp, a.alamat, a.telepon, a.tempat_lahir, a.tanggal_lahir
                              FROM pengajuan_pinjaman pp
                              JOIN jenis_pinjaman jp ON jp.id = pp.jenis_pinjaman_id
                              JOIN anggota a ON a.id = pp.anggota_id
                              LEFT JOIN jaminan_pinjaman j ON j.pengajuan_id = pp.id
                              WHERE pp.id = ? AND pp.status = 'disetujui'
                              AND NOT EXISTS (SELECT 1 FROM layanan_tunda_cair tc WHERE tc.pengajuan_id=pp.id AND tc.status IN ('menunggu','disetujui')) FOR UPDATE");
        $stmt->execute([$id]);
        $pengajuan = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$pengajuan) {
            throw new Exception("Pengajuan tidak ditemukan atau belum disetujui Admin");
        }
        $stmt = $db->prepare("SELECT COUNT(*) FROM pinjaman WHERE pengajuan_id = ?");
        $stmt->execute([$id]);
        if ((int) $stmt->fetchColumn() > 0) {
            throw new Exception("Pengajuan ini sudah dicairkan");
        }

        layananValidasiPrioritas($db,$pengajuan['anggota_id'],$pengajuan['jumlah_pinjaman'],$pengajuan['memerlukan_jaminan'],!empty($pengajuan['fasilitas_prioritas']));
        $adalah_gadai = !empty($pengajuan['memerlukan_jaminan']);
        if ($adalah_gadai && empty($pengajuan['jaminan_id'])) {
            throw new Exception("Data barang jaminan tidak ditemukan");
        }
        $foto = validasiFotoUpload('foto_nasabah', 'Foto nasabah saat menerima uang', 1024 * 1024, true);
        $foto_barang = validasiFotoUpload('foto_barang_jaminan', 'Foto barang jaminan', 1024 * 1024, $adalah_gadai);
        $foto_nasabah_barang = validasiFotoUpload('foto_nasabah_barang', 'Foto nasabah dengan barang jaminan', 1024 * 1024, $adalah_gadai);
        $lampiran_upload = validasiBanyakFotoUpload('surat_tambahan', 'Lampiran surat perjanjian', 1024 * 1024, 10);
        $versi_perjanjian = 'lengkap';
        $nama_saksi_1 = trim($_POST['nama_saksi_1'] ?? '');
        $nama_saksi_2 = trim($_POST['nama_saksi_2'] ?? '');
        if ($nama_saksi_1 === '' || $nama_saksi_2 === '') throw new Exception('Nama kedua saksi wajib diisi');
        $ttd_nasabah = validasiTtdDataUrl(bacaTtdRequest('ttd_nasabah', 'TTD nasabah'), 'TTD nasabah');
        $ttd_teller = validasiTtdDataUrl(bacaTtdRequest('ttd_teller', 'TTD Teller'), 'TTD Teller');
        $ttd_saksi_1 = validasiTtdDataUrl(bacaTtdRequest('ttd_saksi_1', 'TTD saksi 1'), 'TTD saksi 1');
        $ttd_saksi_2 = validasiTtdDataUrl(bacaTtdRequest('ttd_saksi_2', 'TTD saksi 2'), 'TTD saksi 2');
        $aturan_surat = pengaturanPerjanjian($db);
        $ttd_admin = validasiTtdDataUrl($aturan_surat['ttd_admin'] ?? '', 'TTD Admin pada menu Pengaturan');
        $stmtAdmin = $db->prepare("SELECT nama_lengkap FROM users WHERE id = ?");
        $stmtAdmin->execute([$aturan_surat['updated_by']]);
        $nama_admin_ttd = $stmtAdmin->fetchColumn() ?: 'Administrator';

        // Tanggal pencairan diisi Teller (boleh tanggal lampau untuk data lama, tidak boleh masa depan
        // dan tidak boleh sebelum tanggal pengajuan). Jatuh tempo dihitung dari tanggal ini.
        $tgl_cair = isset($_POST['tanggal_pencairan']) ? trim($_POST['tanggal_pencairan']) : '';
        $dt = DateTime::createFromFormat('Y-m-d', $tgl_cair);
        if (!$dt || $dt->format('Y-m-d') !== $tgl_cair) {
            throw new Exception("Tanggal pencairan tidak valid");
        }
        if ($tgl_cair > date('Y-m-d')) {
            throw new Exception("Tanggal pencairan tidak boleh di masa depan");
        }
        if ($tgl_cair < date('Y-m-d', strtotime($pengajuan['tanggal_pengajuan']))) {
            throw new Exception("Tanggal pencairan tidak boleh lebih awal dari tanggal pengajuan ("
                . formatTanggalUi($pengajuan['tanggal_pengajuan']) . ")");
        }
        $mundur = $tgl_cair < date('Y-m-d') ? 1 : 0;

        $jumlah_cicilan = hitungDariPinjaman($pengajuan)['jumlah_cicilan'];
        $sudah_dibayar = isset($_POST['sudah_dibayar']) && $_POST['sudah_dibayar'] !== '' ? (int) $_POST['sudah_dibayar'] : 0;
        if ($sudah_dibayar < 0 || $sudah_dibayar > $jumlah_cicilan) {
            throw new Exception("Cicilan yang sudah dibayar harus 0 sampai $jumlah_cicilan");
        }
        if ($sudah_dibayar > 0 && !$mundur) {
            throw new Exception("Cicilan yang sudah dibayar hanya untuk pencairan dengan tanggal lampau");
        }

        $foto_path = simpanFotoUpload($foto, 'pencairan');
        if ($adalah_gadai) {
            $foto_barang_path = simpanFotoUpload($foto_barang, 'jaminan');
            $foto_nasabah_barang_path = simpanFotoUpload($foto_nasabah_barang, 'jaminan');
        }
        // Dana dapat dicairkan belakangan; tanggal tagihan tetap mengikuti
        // tanggal pengajuan yang telah dipilih nasabah.
        $tanggal_jadwal = tanggalMulaiJadwal($pengajuan);
        $pinjaman_id = buatPinjamanDanJadwal($db, $pengajuan, $tanggal_jadwal, $tgl_cair);
        if (!$pinjaman_id) {
            throw new Exception("Pinjaman gagal dibuat");
        }
        $db->prepare("UPDATE pinjaman SET foto_pencairan = ?, dicairkan_oleh = ?, input_mundur = ? WHERE id = ?")
           ->execute([$foto_path, $_SESSION['user_id'], $mundur, $pinjaman_id]);
        if ($adalah_gadai) {
            $db->prepare("UPDATE jaminan_pinjaman
                          SET foto_barang = ?, foto_nasabah_barang = ?
                          WHERE id = ?")
               ->execute([$foto_barang_path, $foto_nasabah_barang_path, $pengajuan['jaminan_id']]);
        }
        foreach ($lampiran_upload as $info) $lampiran_paths[] = simpanFotoUpload($info, 'perjanjian');
        tandaiCicilanDimuka($db, $pinjaman_id, $sudah_dibayar);
        $db->prepare("UPDATE pengajuan_pinjaman SET status = 'dicairkan' WHERE id = ?")->execute([$id]);

        $nama_teller = $_SESSION['nama_lengkap'] ?? ($_SESSION['username'] ?? 'Teller');
        $hitung_surat = hitungDariPinjaman($pengajuan);
        $stmtJadwal = $db->prepare("SELECT angsuran_ke,pokok,bunga,jumlah_angsuran,tanggal_jatuh_tempo FROM angsuran WHERE pinjaman_id=? ORDER BY angsuran_ke");
        $stmtJadwal->execute([$pinjaman_id]);
        $jadwal_surat = $stmtJadwal->fetchAll(PDO::FETCH_ASSOC);
        $jatuh_tempo_akhir = $jadwal_surat ? end($jadwal_surat)['tanggal_jatuh_tempo'] : $tgl_cair;
        $snapshot = [
            'koperasi'=>$koperasi, 'anggota'=>['nama'=>$pengajuan['nama_lengkap'],'kode'=>$adalah_gadai?($pengajuan['kode_anggota_jaminan'] ?: $pengajuan['kode_anggota']):$pengajuan['kode_anggota'],'nik'=>$pengajuan['ktp'],'alamat'=>$pengajuan['alamat'],'telepon'=>$pengajuan['telepon'],'tempat_lahir'=>$pengajuan['tempat_lahir'],'tanggal_lahir'=>$pengajuan['tanggal_lahir']],
            'pihak_pertama'=>['nama'=>$aturan_surat['nama_pihak_pertama'] ?: $koperasi['nama'],'nik'=>$aturan_surat['nik_pihak_pertama'],'alamat'=>$aturan_surat['alamat_pihak_pertama'] ?? '','telepon'=>$aturan_surat['telepon_pihak_pertama'] ?? ''],
            'pinjaman'=>['id'=>$pinjaman_id,'jenis'=>$pengajuan['nama_jenis'],'jumlah'=>$hitung_surat['pokok'],'tenor'=>$hitung_surat['tenor'],'bunga'=>$hitung_surat['bunga_persen'],'total'=>$hitung_surat['total'],'angsuran'=>$hitung_surat['angsuran'],'frekuensi'=>$hitung_surat['frekuensi'],'jumlah_cicilan'=>$hitung_surat['jumlah_cicilan'],'tanggal_pencairan'=>$tgl_cair,'jatuh_tempo'=>$jatuh_tempo_akhir,'tujuan'=>$pengajuan['tujuan_pinjaman'],'hari_angsuran'=>$pengajuan['hari_angsuran']],
            'jadwal_angsuran'=>$jadwal_surat,
            'jaminan'=>['ada'=>$adalah_gadai,'jenis'=>$pengajuan['jenis_barang'] ?? null,'detail'=>trim(($pengajuan['identitas_barang'] ?? '').' '.($pengajuan['deskripsi_barang'] ?? ''))],
            'isi_perjanjian'=>$versi_perjanjian==='lengkap'?$aturan_surat['isi_lengkap']:$aturan_surat['isi_ringkas'],
            'penandatangan'=>['nasabah'=>$pengajuan['nama_lengkap'],'teller'=>$nama_teller,'admin'=>$nama_admin_ttd,'jabatan_admin'=>$aturan_surat['jabatan_admin'],'saksi_1'=>$nama_saksi_1,'saksi_2'=>$nama_saksi_2],
            'foto'=>['pencairan'=>$foto_path,'barang'=>$foto_barang_path,'nasabah_barang'=>$foto_nasabah_barang_path], 'dibuat_pada'=>date('Y-m-d H:i:s')
        ];
        $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $nomor = nomorPerjanjian(['id'=>$pinjaman_id,'tanggal_pencairan'=>$tgl_cair]);
        $hash = hash('sha256', $json.$ttd_nasabah.$ttd_teller.$ttd_admin.$ttd_saksi_1.$ttd_saksi_2);
        $stmt = $db->prepare("INSERT INTO perjanjian_pinjaman (pinjaman_id,nomor_perjanjian,versi,snapshot_json,nama_saksi_1,nama_saksi_2,ttd_nasabah,ttd_teller,ttd_admin,ttd_saksi_1,ttd_saksi_2,hash_dokumen,ditandatangani_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW())");
        $stmt->execute([$pinjaman_id,$nomor,$versi_perjanjian,$json,$nama_saksi_1,$nama_saksi_2,$ttd_nasabah,$ttd_teller,$ttd_admin,$ttd_saksi_1,$ttd_saksi_2,$hash]);
        $perjanjian_id = $db->lastInsertId();
        $stmtLampiran = $db->prepare("INSERT INTO lampiran_perjanjian (perjanjian_id,file_path,urutan) VALUES (?,?,?)");
        foreach ($lampiran_paths as $i=>$path) $stmtLampiran->execute([$perjanjian_id,$path,$i+1]);

        // Cicilan lama yang sudah lewat jatuh tempo dan belum dibayar langsung kena denda sesuai jatuh tempo aslinya
        perbaruiDendaOtomatis($db, true, $pinjaman_id);
        $db->commit();
        $committed = true;
        unset($_SESSION['error']);
        $_SESSION['success'] = "Pinjaman berhasil dicairkan tanggal " . formatTanggalUi($tgl_cair)
            . ". Jadwal angsuran dibuat dari tanggal pengajuan"
            . ($sudah_dibayar > 0 ? ", cicilan 1-$sudah_dibayar ditandai sudah dibayar (tidak masuk kas)" : "")
            . ($mundur ? " (input mundur)" : "") . ".";
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        if (!$committed) {
            hapusFotoUpload($foto_path); hapusFotoUpload($foto_barang_path); hapusFotoUpload($foto_nasabah_barang_path);
            foreach ($lampiran_paths as $path) hapusFotoUpload($path);
        }
        unset($_SESSION['success']);
        $_SESSION['error'] = "Gagal memproses pencairan: " . htmlspecialchars($e->getMessage())
            . (migrasiRevisiBelumDijalankan($db) ? " (database belum di-update, jalankan file migrasi)" : "");
    }
    $db->setAttribute(PDO::ATTR_ERRMODE, $errmode);
    header("Location: pencairan.php");
    exit();
}

// Lengkapi foto pencairan untuk pinjaman migrasi / tanpa dokumen.
if ($_POST && isset($_POST['lengkapi_foto_pencairan'])) {
    require_once __DIR__ . '/lengkapi_foto_pencairan.php';
    try {
        layananValidasiToken();
        prosesLengkapiFotoPencairan($db, (int)($_POST['pinjaman_id'] ?? 0), (int)($_SESSION['user_id'] ?? 0));
        $_SESSION['success'] = 'Foto pencairan berhasil dilengkapi.';
    } catch (Throwable $e) {
        $_SESSION['error'] = 'Gagal melengkapi foto: ' . $e->getMessage();
    }
    header('Location: pencairan.php');
    exit();
}

$query = "SELECT pp.*, a.nama_lengkap,
          CASE WHEN jp.memerlukan_jaminan = 1 THEN COALESCE(a.kode_anggota_jaminan, a.kode_anggota) ELSE a.kode_anggota END AS kode_anggota,
          jp.nama_jenis,
          jp.maksimal_pinjaman, jp.memerlukan_jaminan, u.nama_lengkap as admin_name,
          j.jenis_barang, j.foto_barang, j.foto_nasabah_barang,
          p.id as pinjaman_id, p.kode_pinjaman, p.tanggal_pencairan, p.status as status_pinjaman,
          p.foto_pencairan, uc.nama_lengkap as teller_name,
          pr.id AS perjanjian_id, tc.id AS tc_id, tc.status AS status_tc, tc.alasan AS alasan_tc, tc.catatan_admin AS catatan_tc,
          (SELECT COUNT(*) FROM angsuran WHERE pinjaman_id = p.id AND status <> 'lunas') AS cicilan_belum,
          (SELECT COUNT(*) FROM angsuran WHERE pinjaman_id = p.id) AS total_cicilan
          FROM pengajuan_pinjaman pp
          JOIN anggota a ON pp.anggota_id = a.id
          JOIN jenis_pinjaman jp ON pp.jenis_pinjaman_id = jp.id
          LEFT JOIN users u ON pp.admin_id = u.id
          LEFT JOIN jaminan_pinjaman j ON j.pengajuan_id = pp.id
          LEFT JOIN pinjaman p ON pp.id = p.pengajuan_id
          LEFT JOIN users uc ON p.dicairkan_oleh = uc.id
          LEFT JOIN perjanjian_pinjaman pr ON pr.pinjaman_id = p.id
          LEFT JOIN layanan_tunda_cair tc ON tc.pengajuan_id = pp.id
          WHERE (pp.status IN ('menunggu', 'disetujui', 'dicairkan', 'develop')
          OR (pp.status = '' AND pp.tanggal_persetujuan IS NOT NULL))
          ORDER BY COALESCE(pp.tanggal_persetujuan, pp.tanggal_pengajuan) DESC";
$stmt = $db->prepare($query);
$stmt->execute();
$pengajuan_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

$query_stats = "SELECT
    COUNT(*) as total_pengajuan,
    COUNT(CASE WHEN status = 'menunggu' OR (status = '' AND tanggal_persetujuan IS NULL) THEN 1 END) as menunggu,
    COUNT(CASE WHEN status = 'disetujui' OR status = 'develop' OR (status = '' AND tanggal_persetujuan IS NOT NULL) THEN 1 END) as disetujui,
    COUNT(CASE WHEN status = 'dicairkan' THEN 1 END) as dicairkan,
    COUNT(CASE WHEN status = 'ditolak' THEN 1 END) as ditolak
    FROM pengajuan_pinjaman";
$stmt_stats = $db->prepare($query_stats);
$stmt_stats->execute();
$stats = $stmt_stats->fetch(PDO::FETCH_ASSOC);
$stats['disetujui'] = (int) $db->query("SELECT COUNT(*) FROM pengajuan_pinjaman pp WHERE pp.status = 'disetujui'
                                        AND NOT EXISTS (SELECT 1 FROM pinjaman p WHERE p.pengajuan_id = pp.id)")->fetchColumn();
$stats['dicairkan'] = (int) $db->query("SELECT COUNT(*) FROM pinjaman")->fetchColumn();

$statusClassMap = [
    'menunggu' => 'warning',
    'disetujui' => 'success',
    'dicairkan' => 'info',
    'ditolak' => 'danger',
    'develop' => 'success',
    'batal_cair' => 'secondary',
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Status Pencairan Pinjaman - <?php echo APP_NAME; ?></title>
    <?php $uiAssetPrefix = '../'; include __DIR__ . '/layout/assets.php'; ?>
</head>
<body>
    <?php include __DIR__ . '/layout/shell_start.php'; ?>
            <div class="ui-tx-page">
                <header class="ui-dash-header">
                    <div class="ui-dash-header-icon ui-tx-icon-cair" aria-hidden="true"><i class="ti ti-cash"></i></div>
                    <div class="ui-dash-header-copy">
                        <p class="ui-dash-eyebrow mb-0">Pinjaman</p>
                        <h1 class="ui-dash-title">Pencairan Pinjaman</h1>
                        <p class="ui-dash-meta mb-0">Proses pencairan pengajuan yang sudah disetujui</p>
                    </div>
                    <div class="ui-dash-header-actions">
                        <?php if (isAdmin()): ?>
                        <a href="?sync=1" class="btn btn-outline-light" onclick="return confirm('Sinkronisasi data pengajuan dengan pinjaman?')">
                            <i class="ti ti-refresh me-1" aria-hidden="true"></i>
                            <span class="d-none d-sm-inline">Sinkronisasi</span>
                        </a>
                        <?php endif; ?>
                        <a href="persetujuan.php" class="btn ui-tx-btn-cair-ghost">
                            <i class="ti ti-circle-check me-1" aria-hidden="true"></i>
                            <span class="d-none d-sm-inline">Persetujuan</span><span class="d-sm-none">Setuju</span>
                        </a>
                    </div>
                </header>

                <div class="alert alert-info ui-pc-info" role="status" data-ui-toast-skip>
                    <i class="ti ti-info-circle me-1" aria-hidden="true"></i>
                    Pengajuan disetujui Admin diproses di sini.
                    <?php if ($bolehCairkan): ?>
                    <strong>Proses Pencairan</strong> wajib unggah foto nasabah; jadwal angsuran dari <?php echo JADWAL_DARI_PENGAJUAN ? 'tanggal pengajuan' : 'tanggal pencairan'; ?>.
                    <?php else: ?>
                    Mode lihat saja untuk akun ini; pencairan dilakukan staff yang berwenang.
                    <?php endif; ?>
                </div>

                <?php ui_flash(); ?>

                <div class="ui-pc-kpi">
                <?php ui_stat_grid([
                    ['label' => 'Total pengajuan', 'value' => (int)$stats['total_pengajuan'], 'tone' => 'primary', 'icon' => 'ti ti-file-text', 'hint' => 'Semua'],
                    ['label' => 'Menunggu', 'value' => (int)$stats['menunggu'], 'tone' => 'warning', 'icon' => 'ti ti-clock', 'hint' => 'Antrian'],
                    ['label' => 'Siap cair', 'value' => (int)$stats['disetujui'], 'tone' => 'success', 'icon' => 'ti ti-circle-check', 'hint' => 'Disetujui'],
                    ['label' => 'Dicairkan', 'value' => (int)$stats['dicairkan'], 'tone' => 'info', 'icon' => 'ti ti-cash', 'hint' => 'Sudah cair'],
                ]); ?>
                </div>

                <div class="card mt-3" id="riwayat">
                    <div class="card-header ui-dash-sec">
                        <span class="ui-dash-sec-title"><i class="ti ti-list" aria-hidden="true"></i>Daftar status pencairan</span>
                        <span class="ui-dash-sec-note"><?= (int)count($pengajuan_list) ?> data</span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive ui-table-scroll">
                            <table class="table table-hover align-middle mb-0 js-datatable"
                                   data-dt-page-length="10"
                                   data-dt-order="0,asc;1,desc"
                                   data-dt-filter="6"
                                   data-dt-date-col="1">
                                <thead>
                                    <tr>
                                        <th class="dt-prio">Prio</th>
                                        <th>Tanggal</th>
                                        <th>Kode</th>
                                        <th>Anggota</th>
                                        <th>Jenis</th>
                                        <th class="text-end">Jumlah</th>
                                        <th>Status</th>
                                        <th class="text-end">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pengajuan_list as $pengajuan):
                                        $status = $pengajuan['status'];
                                        if ($status == 'develop' || ($status == '' && $pengajuan['tanggal_persetujuan'])) {
                                            $status = 'disetujui';
                                        }
                                        if (!empty($pengajuan['pinjaman_id'])) {
                                            $status = 'dicairkan';
                                        }
                                        $belum_cair = ($status == 'disetujui' && empty($pengajuan['pinjaman_id']));
                                        $status_tc = $pengajuan['status_tc'] ?? '';
                                        $statusLabel = $belum_cair ? 'Siap cair' : ucfirst((string)$status);
                                        if ($status_tc === 'menunggu') {
                                            $statusLabel = 'TC menunggu';
                                        } elseif ($status_tc === 'disetujui' && $belum_cair) {
                                            $statusLabel = 'TC disetujui';
                                        }
                                        $badge = $statusClassMap[$status] ?? 'secondary';
                                        if ($status_tc === 'menunggu') {
                                            $badge = 'warning';
                                        } elseif ($status_tc === 'disetujui' && $belum_cair) {
                                            $badge = 'secondary';
                                        }
                                        $tanggalUrut = $pengajuan['tanggal_pencairan'] ?: ($pengajuan['tanggal_persetujuan'] ?: $pengajuan['tanggal_pengajuan']);
                                        $ts = strtotime((string)$tanggalUrut);
                                        $orderTs = $ts ? date('Y-m-d H:i:s', $ts) : '';
                                        $day = $ts ? date('Y-m-d', $ts) : '';
                                        // 0 = butuh aksi role; semakin kecil semakin atas
                                        $prio = 9;
                                        if ($bolehKeputusanTc && $status_tc === 'menunggu') {
                                            $prio = 0;
                                        } elseif ($bolehCairkan && $belum_cair && !in_array($status_tc, ['menunggu', 'disetujui'], true)) {
                                            $prio = 1;
                                        } elseif (!empty($pengajuan['pinjaman_id']) && empty($pengajuan['foto_pencairan'])) {
                                            $prio = 2;
                                        }

                                        $menuItems = [
                                            [
                                                'label' => 'Detail',
                                                'icon' => 'ti ti-eye',
                                                'attrs' => [
                                                    'data-bs-toggle' => 'modal',
                                                    'data-bs-target' => '#detailModal',
                                                    'data-kode' => $pengajuan['kode_pengajuan'],
                                                    'data-nama' => $pengajuan['nama_lengkap'],
                                                    'data-kode_anggota' => $pengajuan['kode_anggota'],
                                                    'data-jenis' => $pengajuan['nama_jenis'],
                                                    'data-jumlah' => $pengajuan['jumlah_pinjaman'],
                                                    'data-tenor' => labelTenorRow($pengajuan),
                                                    'data-bunga' => labelBungaRow($pengajuan),
                                                    'data-tujuan' => $pengajuan['tujuan_pinjaman'],
                                                    'data-status' => $status,
                                                    'data-status-label' => $statusLabel,
                                                    'data-tanggal' => $pengajuan['tanggal_persetujuan'] ? formatTanggalUi($pengajuan['tanggal_persetujuan'], true) : '-',
                                                    'data-catatan' => $pengajuan['catatan_admin'],
                                                    'data-admin' => $pengajuan['admin_name'] ?: '-',
                                                    'data-kode_pinjaman' => $pengajuan['kode_pinjaman'] ?: '-',
                                                    'data-tanggal_pencairan' => $pengajuan['tanggal_pencairan'] ? formatTanggalUi($pengajuan['tanggal_pencairan']) : '-',
                                                    'data-teller' => $pengajuan['teller_name'] ?: '-',
                                                ],
                                            ],
                                        ];
                                        if ($belum_cair && !in_array($status_tc, ['menunggu', 'disetujui'], true)) {
                                            if ($bolehCairkan) {
                                                $menuItems[] = [
                                                    'label' => 'Proses Pencairan',
                                                    'icon' => 'ti ti-moneybag',
                                                    'class' => 'text-success',
                                                    'attrs' => [
                                                        'data-ui-modal' => '#pencairanModal',
                                                        'data-id' => (int)$pengajuan['id'],
                                                        'data-nama' => $pengajuan['nama_lengkap'],
                                                        'data-kode' => $pengajuan['kode_pengajuan'],
                                                        'data-jumlah' => $pengajuan['jumlah_pinjaman'],
                                                        'data-tgl-pengajuan' => date('Y-m-d', strtotime($pengajuan['tanggal_pengajuan'])),
                                                        'data-cicilan' => (int)hitungDariPinjaman($pengajuan)['jumlah_cicilan'],
                                                        'data-frekuensi' => isset($pengajuan['frekuensi']) ? $pengajuan['frekuensi'] : 'bulanan',
                                                        'data-gadai' => !empty($pengajuan['memerlukan_jaminan']) ? '1' : '0',
                                                        'data-jenis-barang' => $pengajuan['jenis_barang'] ?: 'barang',
                                                        'data-ringkas' => labelTenorRow($pengajuan) . ' | ' . ringkasanAngsuran(hitungDariPinjaman($pengajuan)),
                                                    ],
                                                ];
                                            }
                                            $menuItems[] = [
                                                'label' => 'Draft perjanjian',
                                                'icon' => 'ti ti-file-description',
                                                'title' => 'Cetak surat perjanjian sebelum pencairan',
                                                'href' => 'cetak_draft_perjanjian.php?id=' . (int)$pengajuan['id'],
                                                'target' => '_blank',
                                            ];
                                        }
                                        if ($belum_cair && empty($status_tc)) {
                                            $menuItems[] = [
                                                'label' => 'Tunda cair (TC)',
                                                'icon' => 'ti ti-player-pause',
                                                'attrs' => [
                                                    'data-bs-toggle' => 'modal',
                                                    'data-bs-target' => '#tcModal',
                                                    'data-id' => (int)$pengajuan['id'],
                                                    'data-nama' => $pengajuan['nama_lengkap'],
                                                    'data-kode' => $pengajuan['kode_pengajuan'],
                                                ],
                                            ];
                                        }
                                        if ($status_tc === 'menunggu') {
                                            if ($bolehKeputusanTc && !empty($pengajuan['tc_id'])) {
                                                $menuItems[] = [
                                                    'label' => 'Putuskan TC',
                                                    'icon' => 'ti ti-gavel',
                                                    'class' => 'text-warning',
                                                    'attrs' => [
                                                        'data-bs-toggle' => 'modal',
                                                        'data-bs-target' => '#keputusanTcModal',
                                                        'data-tc-id' => (int)$pengajuan['tc_id'],
                                                        'data-nama' => $pengajuan['nama_lengkap'],
                                                        'data-kode' => $pengajuan['kode_pengajuan'],
                                                        'data-alasan' => (string)($pengajuan['alasan_tc'] ?? ''),
                                                    ],
                                                ];
                                            } else {
                                                $menuItems[] = ['label' => 'TC menunggu Admin', 'icon' => 'ti ti-hourglass', 'disabled' => true];
                                            }
                                        } elseif ($status_tc === 'disetujui') {
                                            $menuItems[] = [
                                                'label' => 'TC disetujui',
                                                'icon' => 'ti ti-check',
                                                'disabled' => true,
                                                'title' => (string)($pengajuan['alasan_tc'] ?? ''),
                                            ];
                                        } elseif ($status_tc === 'ditolak') {
                                            $menuItems[] = [
                                                'label' => 'TC ditolak',
                                                'icon' => 'ti ti-ban',
                                                'disabled' => true,
                                                'title' => 'TC yang sudah ditolak tidak dapat diajukan ulang',
                                            ];
                                        }
                                        $fotoPencairanAda = !empty($pengajuan['foto_pencairan']) && fotoFileAda($pengajuan['foto_pencairan']);
                                        if ($fotoPencairanAda) {
                                            $menuItems[] = [
                                                'label' => 'Foto nasabah',
                                                'icon' => 'ti ti-camera',
                                                'onclick' => 'lihatDokumen(' . json_encode(urlFoto($pengajuan['foto_pencairan']), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) . ', ' . json_encode('Foto nasabah - ' . $pengajuan['nama_lengkap'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) . ')',
                                            ];
                                        } elseif (!empty($pengajuan['pinjaman_id'])) {
                                            // Path di DB ada tapi file hilang (sering setelah reset tanpa salin uploads/) → minta unggah ulang.
                                            $menuItems[] = [
                                                'label' => !empty($pengajuan['foto_pencairan']) ? 'Lengkapi foto (file hilang)' : 'Lengkapi foto',
                                                'icon' => 'ti ti-camera',
                                                'class' => !empty($pengajuan['foto_pencairan']) ? 'text-warning' : '',
                                                'title' => !empty($pengajuan['foto_pencairan'])
                                                    ? 'Path foto ada di database, tetapi file tidak ditemukan di folder uploads/'
                                                    : 'Foto pencairan belum diunggah',
                                                'attrs' => [
                                                    'data-bs-toggle' => 'modal',
                                                    'data-bs-target' => '#lengkapiFotoModal',
                                                    'data-lengkapi-foto' => '1',
                                                    'data-id' => (int)$pengajuan['pinjaman_id'],
                                                    'data-kode' => $pengajuan['kode_pinjaman'] ?: $pengajuan['kode_pengajuan'],
                                                    'data-nama' => $pengajuan['nama_lengkap'],
                                                    'data-gadai' => !empty($pengajuan['memerlukan_jaminan']) ? '1' : '0',
                                                ],
                                            ];
                                        }
                                        if (!empty($pengajuan['foto_barang'])) {
                                            $menuItems[] = [
                                                'label' => 'Foto barang jaminan',
                                                'icon' => 'ti ti-box',
                                                'onclick' => 'lihatDokumen(' . json_encode(urlFoto($pengajuan['foto_barang']), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) . ', ' . json_encode('Foto ' . (string)$pengajuan['jenis_barang'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) . ')',
                                            ];
                                        }
                                        if (!empty($pengajuan['foto_nasabah_barang'])) {
                                            $menuItems[] = [
                                                'label' => 'Foto nasabah & barang',
                                                'icon' => 'ti ti-tag',
                                                'onclick' => 'lihatDokumen(' . json_encode(urlFoto($pengajuan['foto_nasabah_barang']), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) . ', "Foto nasabah dengan barang jaminan")',
                                            ];
                                        }
                                        if (!empty($pengajuan['pinjaman_id'])) {
                                            $menuItems[] = [
                                                'label' => 'Cetak slip pencairan',
                                                'icon' => 'ti ti-printer',
                                                'href' => 'cetak_slip_pencairan.php?id=' . (int)$pengajuan['pinjaman_id'],
                                                'target' => '_blank',
                                            ];
                                            if (!empty($pengajuan['perjanjian_id'])) {
                                                $menuItems[] = [
                                                    'label' => 'PDF perjanjian',
                                                    'icon' => 'ti ti-file-type-pdf',
                                                    'href' => 'cetak_perjanjian.php?id=' . (int)$pengajuan['pinjaman_id'],
                                                    'target' => '_blank',
                                                ];
                                            } else {
                                                // Migrasi / pencairan lama tanpa arsip TTD: tetap bisa cetak dari data pengajuan.
                                                $menuItems[] = [
                                                    'label' => 'PDF perjanjian',
                                                    'icon' => 'ti ti-file-type-pdf',
                                                    'href' => 'cetak_draft_perjanjian.php?id=' . (int)$pengajuan['id'],
                                                    'target' => '_blank',
                                                    'title' => 'Arsip TTD digital belum ada — cetak dari data pinjaman',
                                                ];
                                            }
                                        }
                                    ?>
                                    <tr<?= $prio < 9 ? ' class="ui-row-action"' : '' ?>>
                                        <td><?= (int)$prio ?></td>
                                        <td data-order="<?= htmlspecialchars($orderTs) ?>" data-date="<?= htmlspecialchars($day) ?>">
                                            <?= htmlspecialchars(formatTanggalUi($tanggalUrut, true, true)) ?>
                                        </td>
                                        <td>
                                            <code><?= htmlspecialchars($pengajuan['kode_pengajuan']) ?></code>
                                            <?php if (!empty($pengajuan['kode_pinjaman'])): ?>
                                            <div class="small text-success"><?= htmlspecialchars($pengajuan['kode_pinjaman']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="fw-semibold"><?= htmlspecialchars($pengajuan['nama_lengkap']) ?></div>
                                            <small class="text-muted"><?= htmlspecialchars((string)$pengajuan['kode_anggota']) ?></small>
                                        </td>
                                        <td>
                                            <div><?= htmlspecialchars($pengajuan['nama_jenis']) ?></div>
                                            <small class="text-muted"><?= htmlspecialchars(labelTenorRow($pengajuan)) ?> · <?= htmlspecialchars(labelBungaRow($pengajuan)) ?></small>
                                        </td>
                                        <td class="text-end fw-semibold"><?= formatRupiah($pengajuan['jumlah_pinjaman']) ?></td>
                                        <td data-filter="<?= htmlspecialchars($statusLabel) ?>">
                                            <span class="badge bg-<?= htmlspecialchars($badge) ?>"><?= htmlspecialchars($statusLabel) ?></span>
                                            <?php if ($status_tc === 'menunggu' && !empty($pengajuan['alasan_tc'])): ?>
                                            <div class="small text-muted mt-1 ui-pc-tc-alasan"><?php
                                                $alasanShort = (string)$pengajuan['alasan_tc'];
                                                if (strlen($alasanShort) > 80) { $alasanShort = substr($alasanShort, 0, 77) . '…'; }
                                                echo htmlspecialchars($alasanShort);
                                            ?></div>
                                            <?php elseif (!empty($pengajuan['admin_name'])): ?>
                                            <div class="small text-muted mt-1">Oleh: <?= htmlspecialchars($pengajuan['admin_name']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end table-actions"><?= ui_action_menu($menuItems) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <?php include __DIR__ . '/layout/shell_end.php'; ?>

    <div class="modal fade ui-tx-modal ui-tx-modal-cair" id="detailModal" tabindex="-1" aria-labelledby="detailModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title h5" id="detailModalLabel">Detail pencairan</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                </div>
                <div class="modal-body">
                    <div class="ui-pj-detail">
                        <div class="ui-pj-detail-hero">
                            <div class="ui-pj-detail-hero-main min-w-0">
                                <div class="ui-pj-detail-kode" id="detail_kode">—</div>
                                <div class="ui-pj-detail-nama text-truncate" id="detail_nama">—</div>
                                <div class="ui-pj-detail-meta" id="detail_kode_anggota">—</div>
                            </div>
                            <div class="ui-pj-detail-hero-status" id="detail_status">—</div>
                        </div>
                        <div class="ui-pj-detail-amount">
                            <div class="ui-pj-detail-amount-item">
                                <span class="ui-pj-detail-amount-label">Jumlah pinjaman</span>
                                <strong class="ui-pj-detail-amount-value" id="detail_jumlah">—</strong>
                            </div>
                            <div class="ui-pj-detail-amount-item">
                                <span class="ui-pj-detail-amount-label">Jenis</span>
                                <strong class="ui-pj-detail-amount-sub" id="detail_jenis">—</strong>
                            </div>
                        </div>
                        <div class="ui-pj-detail-grid">
                            <section class="ui-pj-detail-card">
                                <h3 class="ui-pj-detail-card-title"><i class="ti ti-percentage" aria-hidden="true"></i>Syarat pinjaman</h3>
                                <dl class="ui-pj-detail-dl">
                                    <div><dt>Tenor</dt><dd id="detail_tenor">—</dd></div>
                                    <div><dt>Bunga</dt><dd id="detail_bunga">—</dd></div>
                                    <div><dt>Disetujui</dt><dd id="detail_tanggal">—</dd></div>
                                </dl>
                            </section>
                            <section class="ui-pj-detail-card">
                                <h3 class="ui-pj-detail-card-title"><i class="ti ti-cash" aria-hidden="true"></i>Pencairan</h3>
                                <dl class="ui-pj-detail-dl">
                                    <div><dt>Kode pinjaman</dt><dd id="detail_kode_pinjaman">—</dd></div>
                                    <div><dt>Tgl cair</dt><dd id="detail_tanggal_pencairan">—</dd></div>
                                    <div><dt>Oleh</dt><dd id="detail_admin">—</dd></div>
                                    <div><dt>Teller</dt><dd id="detail_teller">—</dd></div>
                                </dl>
                            </section>
                            <section class="ui-pj-detail-card ui-pj-detail-card-wide">
                                <h3 class="ui-pj-detail-card-title"><i class="ti ti-target" aria-hidden="true"></i>Tujuan pinjaman</h3>
                                <p class="ui-pj-detail-tujuan mb-0" id="detail_tujuan">—</p>
                            </section>
                            <section class="ui-pj-detail-card ui-pj-detail-card-wide">
                                <h3 class="ui-pj-detail-card-title"><i class="ti ti-notes" aria-hidden="true"></i>Catatan admin</h3>
                                <p class="ui-pj-detail-tujuan mb-0" id="detail_catatan">—</p>
                            </section>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Tutup</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade ui-tx-modal ui-tx-modal-tc" id="tcModal" tabindex="-1" aria-labelledby="tcModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="post">
                    <?= layananToken() ?>
                    <input type="hidden" name="ajukan_tc" value="1">
                    <input type="hidden" name="id" id="ajukan_tc_pengajuan_id">
                    <div class="modal-header">
                        <h2 class="modal-title h5" id="tcModalLabel"><i class="ti ti-player-pause me-1" aria-hidden="true"></i>Tunda Cair (TC)</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                    </div>
                    <div class="modal-body">
                        <div class="ui-ps-confirm">
                            <div class="ui-ps-confirm-label">Anggota</div>
                            <div class="ui-ps-confirm-value" id="ajukan_tc_nama">—</div>
                            <div class="ui-ps-confirm-meta">Kode: <span id="ajukan_tc_kode">—</span></div>
                        </div>
                        <label class="form-label">Keterangan alasan TC <span class="text-danger">*</span></label>
                        <textarea name="alasan_tc" class="form-control" rows="4" minlength="5" maxlength="2000" required placeholder="Contoh: Nasabah meminta pencairan ditunda hingga dokumen dilengkapi."></textarea>
                        <p class="ui-pj-section-note mt-2 mb-0">Pengajuan diperiksa Admin. Pencairan tidak dapat diproses selama TC menunggu atau disetujui.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-warning">Ajukan TC</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php if ($bolehKeputusanTc): ?>
    <div class="modal fade ui-tx-modal ui-tx-modal-tc" id="keputusanTcModal" tabindex="-1" aria-labelledby="keputusanTcModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="post">
                    <?= layananToken() ?>
                    <input type="hidden" name="tc_id" id="keputusan_tc_id">
                    <div class="modal-header">
                        <h2 class="modal-title h5" id="keputusanTcModalLabel"><i class="ti ti-gavel me-1" aria-hidden="true"></i>Putuskan Tunda Cair</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                    </div>
                    <div class="modal-body">
                        <div class="ui-ps-confirm">
                            <div class="ui-ps-confirm-label">Anggota</div>
                            <div class="ui-ps-confirm-value" id="keputusan_tc_nama">—</div>
                            <div class="ui-ps-confirm-meta">Kode: <span id="keputusan_tc_kode">—</span></div>
                        </div>
                        <div class="mb-3">
                            <div class="ui-ps-confirm-label mb-1">Alasan Teller</div>
                            <p class="ui-pj-detail-tujuan mb-0" id="keputusan_tc_alasan">—</p>
                        </div>
                        <label class="form-label">Keterangan keputusan Admin <span class="text-danger">*</span></label>
                        <textarea name="catatan_tc" class="form-control" rows="3" minlength="5" maxlength="2000" required placeholder="Alasan menyetujui atau menolak TC"></textarea>
                        <p class="ui-pj-section-note mt-2 mb-0">Approve TC = batal cair. Tolak TC = pengajuan kembali bisa dicairkan.</p>
                    </div>
                    <div class="modal-footer flex-wrap gap-2">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" name="keputusan_tc" value="ditolak" class="btn btn-outline-danger">Tolak TC</button>
                        <button type="submit" name="keputusan_tc" value="disetujui" class="btn btn-success">Approve TC</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="modal fade ui-tx-modal ui-tx-modal-cair" id="pencairanModal" tabindex="-1" aria-labelledby="pencairanModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable modal-dialog-centered">
            <div class="modal-content">
                <form method="POST" action="" enctype="multipart/form-data" id="formPencairan" data-ajax-upload="1"><?php echo layananToken(); ?>
                    <input type="hidden" name="proses_pencairan" value="1">
                    <input type="hidden" name="id" id="pencairan_id">
                    <input type="hidden" name="versi_perjanjian" value="lengkap">
                    <div class="modal-header">
                        <div class="min-w-0">
                            <h2 class="modal-title h5 mb-0" id="pencairanModalLabel"><i class="ti ti-moneybag me-1" aria-hidden="true"></i>Proses Pencairan</h2>
                            <p class="ui-pc-modal-sub mb-0">Unggah foto, isi tanggal, lalu tanda tangan perjanjian</p>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                    </div>
                    <div class="modal-body">
                        <div class="ui-pc-form">
                            <div class="ui-pc-hero">
                                <div class="ui-pc-hero-main min-w-0">
                                    <div class="ui-pc-hero-label">Nasabah</div>
                                    <div class="ui-pc-hero-nama text-truncate" id="pencairan_nama">—</div>
                                    <div class="ui-pc-hero-meta">Kode <code id="pencairan_kode">—</code></div>
                                </div>
                                <div class="ui-pc-hero-amount">
                                    <div class="ui-pc-hero-label">Jumlah cair</div>
                                    <div class="ui-pc-hero-value" id="pencairan_jumlah">—</div>
                                    <div class="ui-pc-hero-meta" id="pencairan_ringkas"></div>
                                </div>
                            </div>

                            <section class="ui-form-section ui-pj-section ui-pc-section">
                                <h6 class="ui-form-section-title"><i class="ti ti-camera" aria-hidden="true"></i>1. Foto pencairan</h6>
                                <p class="ui-pj-section-note mb-3">JPG/PNG/WEBP · otomatis dikompres ke maksimal 1 MB sebelum diunggah. Foto nasabah wajib.</p>
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <div class="ui-pc-upload">
                                            <label class="form-label" for="foto_nasabah">Foto nasabah menerima uang <span class="text-danger">*</span></label>
                                            <input type="file" name="foto_nasabah" id="foto_nasabah" class="form-control form-control-sm" required accept="image/*">
                                            <img id="foto_nasabah_preview" class="ui-pc-upload-preview d-none" alt="Pratinjau nasabah">
                                        </div>
                                    </div>
                                    <div class="col-md-4 d-none" id="wrap_foto_barang">
                                        <div class="ui-pc-upload">
                                            <label class="form-label" for="foto_barang_jaminan" id="label_foto_barang">Foto barang jaminan <span class="text-danger">*</span></label>
                                            <input type="file" name="foto_barang_jaminan" id="foto_barang_jaminan" class="form-control form-control-sm" accept="image/*">
                                            <img id="foto_barang_jaminan_preview" class="ui-pc-upload-preview d-none" alt="Pratinjau barang">
                                        </div>
                                    </div>
                                    <div class="col-md-4 d-none" id="wrap_foto_nasabah_barang">
                                        <div class="ui-pc-upload">
                                            <label class="form-label" for="foto_nasabah_barang">Foto nasabah &amp; barang <span class="text-danger">*</span></label>
                                            <input type="file" name="foto_nasabah_barang" id="foto_nasabah_barang" class="form-control form-control-sm" accept="image/*">
                                            <img id="foto_nasabah_barang_preview" class="ui-pc-upload-preview d-none" alt="Pratinjau nasabah & barang">
                                        </div>
                                    </div>
                                </div>
                            </section>

                            <section class="ui-form-section ui-pj-section ui-pc-section">
                                <h6 class="ui-form-section-title"><i class="ti ti-calendar" aria-hidden="true"></i>2. Tanggal &amp; cicilan</h6>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label" for="tanggal_pencairan">Tanggal pencairan <span class="text-danger">*</span></label>
                                        <input type="date" name="tanggal_pencairan" id="tanggal_pencairan" class="form-control" required
                                               value="<?php echo date('Y-m-d'); ?>" max="<?php echo date('Y-m-d'); ?>">
                                        <p class="ui-pj-section-note mt-1 mb-0">Boleh tanggal lampau untuk data yang sudah cair. Jatuh tempo dihitung dari tanggal ini.</p>
                                    </div>
                                    <div class="col-md-6 d-none" id="blok_dimuka">
                                        <label class="form-label" for="sudah_dibayar">Sudah dibayar sampai cicilan ke-</label>
                                        <input type="number" name="sudah_dibayar" id="sudah_dibayar" class="form-control" min="0" value="0">
                                        <p class="ui-pj-section-note mt-1 mb-0" id="info_dimuka"></p>
                                    </div>
                                </div>
                            </section>

                            <section class="ui-form-section ui-pj-section ui-pc-section">
                                <h6 class="ui-form-section-title"><i class="ti ti-file-pencil" aria-hidden="true"></i>3. Perjanjian &amp; tanda tangan</h6>
                                <div class="ui-pc-versi mb-3">
                                    <i class="ti ti-file-description" aria-hidden="true"></i>
                                    <span>Surat perjanjian versi <strong>lengkap</strong></span>
                                </div>
                                <div class="row g-3 mb-3">
                                    <div class="col-md-6">
                                        <label class="form-label" for="nama_saksi_1">Nama saksi 1 <span class="text-danger">*</span></label>
                                        <input class="form-control" name="nama_saksi_1" id="nama_saksi_1" required autocomplete="name">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label" for="nama_saksi_2">Nama saksi 2 <span class="text-danger">*</span></label>
                                        <input class="form-control" name="nama_saksi_2" id="nama_saksi_2" required autocomplete="name">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label" for="surat_tambahan">Lampiran surat tambahan <span class="text-muted fw-normal">(opsional, maks. 10 gambar)</span></label>
                                        <input type="file" class="form-control form-control-sm" id="surat_tambahan" name="surat_tambahan[]" accept="image/jpeg,image/png,image/webp,image/*" multiple data-max-files="10" data-label="Lampiran surat tambahan">
                                    </div>
                                </div>
                                <div class="row g-3">
                                    <?php foreach (['nasabah'=>'Nasabah','teller'=>'Teller','saksi_1'=>'Saksi 1','saksi_2'=>'Saksi 2'] as $key=>$label): ?>
                                    <div class="col-md-6">
                                        <div class="signature-field ui-pc-sign mb-0">
                                            <div class="signature-field-head">
                                                <div>
                                                    <label class="signature-field-title mb-0">TTD <?php echo $label; ?> *</label>
                                                    <small class="signature-field-help">Gores pada area putih · tidak menggeser halaman di HP</small>
                                                </div>
                                                <span class="signature-status" id="status_<?php echo $key; ?>"><i class="ti ti-signature"></i> Belum</span>
                                            </div>
                                            <div class="signature-canvas-wrap"><canvas id="canvas_<?php echo $key; ?>" class="signature-pad" width="700" height="220"></canvas></div>
                                            <input type="hidden" name="ttd_<?php echo $key; ?>" id="ttd_<?php echo $key; ?>">
                                            <div class="signature-field-foot">
                                                <span class="signature-field-note">Hijau = siap</span>
                                                <button type="button" class="signature-clear btn btn-sm btn-outline-danger clear-signature" data-key="<?php echo $key; ?>"><i class="ti ti-eraser me-1"></i>Hapus</button>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <p class="ui-pj-section-note mt-3 mb-0" id="info_jadwal">
                                    <i class="ti ti-info-circle me-1" aria-hidden="true"></i>
                                    TTD Admin dari Pengaturan. Jadwal angsuran dari tanggal pengajuan; cicilan dimuka tidak masuk kas.
                                </p>
                            </section>
                        </div>
                    </div>
                    <div class="modal-footer ui-pc-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" id="btnCairkan" class="btn btn-success">
                            <i class="ti ti-circle-check me-1" aria-hidden="true"></i> Cairkan pinjaman
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php
    require_once __DIR__ . '/lengkapi_foto_pencairan.php';
    renderModalLengkapiFotoPencairan();
    include __DIR__ . '/modal_dokumen.php';
    ?>
    <script>
    (function () {
        var pencairanModal = document.getElementById('pencairanModal');
        var formPencairan = document.getElementById('formPencairan');
        var tcModal = document.getElementById('tcModal');
        var detailModal = document.getElementById('detailModal');
        if (!pencairanModal || !formPencairan) return;

        if (tcModal) {
            tcModal.addEventListener('show.bs.modal', function (event) {
                var b = event.relatedTarget;
                if (!b || !b.getAttribute) return;
                document.getElementById('ajukan_tc_pengajuan_id').value = b.getAttribute('data-id');
                document.getElementById('ajukan_tc_nama').textContent = b.getAttribute('data-nama');
                document.getElementById('ajukan_tc_kode').textContent = b.getAttribute('data-kode');
            });
        }
        var keputusanTcModal = document.getElementById('keputusanTcModal');
        if (keputusanTcModal) {
            keputusanTcModal.addEventListener('show.bs.modal', function (event) {
                var b = event.relatedTarget;
                if (!b || !b.getAttribute) return;
                document.getElementById('keputusan_tc_id').value = b.getAttribute('data-tc-id');
                document.getElementById('keputusan_tc_nama').textContent = b.getAttribute('data-nama');
                document.getElementById('keputusan_tc_kode').textContent = b.getAttribute('data-kode');
                document.getElementById('keputusan_tc_alasan').textContent = b.getAttribute('data-alasan') || '—';
            });
        }

        function bersihkanBackdropPencairan() {
            document.querySelectorAll('.modal-backdrop').forEach(function (el) { el.remove(); });
            document.body.classList.remove('modal-open');
            document.body.style.removeProperty('overflow');
            document.body.style.removeProperty('padding-right');
        }

        function setPreview(inputId, show) {
            var input = document.getElementById(inputId);
            var img = document.getElementById(inputId + '_preview');
            if (!input || !img) return;
            if (show && input.files && input.files.length) {
                img.src = URL.createObjectURL(input.files[0]);
                img.classList.remove('d-none');
            } else {
                img.classList.add('d-none');
                img.removeAttribute('src');
            }
        }

        function isiFormPencairanDariTombol(b) {
            if (!b || !b.getAttribute) return false;
            var id = b.getAttribute('data-id');
            if (!id) return false;
            document.getElementById('pencairan_id').value = id;
            document.getElementById('pencairan_nama').textContent = b.getAttribute('data-nama') || '—';
            document.getElementById('pencairan_kode').textContent = b.getAttribute('data-kode') || '—';
            document.getElementById('pencairan_jumlah').textContent = 'Rp ' + Number(b.getAttribute('data-jumlah') || 0).toLocaleString('id-ID');
            document.getElementById('pencairan_ringkas').textContent = b.getAttribute('data-ringkas') || '';

            var gadai = b.getAttribute('data-gadai') === '1';
            var wrapBarang = document.getElementById('wrap_foto_barang');
            var wrapNasabahBarang = document.getElementById('wrap_foto_nasabah_barang');
            var fotoBarang = document.getElementById('foto_barang_jaminan');
            var fotoNasabahBarang = document.getElementById('foto_nasabah_barang');
            if (wrapBarang) wrapBarang.classList.toggle('d-none', !gadai);
            if (wrapNasabahBarang) wrapNasabahBarang.classList.toggle('d-none', !gadai);
            if (fotoBarang) fotoBarang.required = gadai;
            if (fotoNasabahBarang) fotoNasabahBarang.required = gadai;
            var jenisBarang = (b.getAttribute('data-jenis-barang') || 'barang').split(' - ')[0];
            var labelBarang = document.getElementById('label_foto_barang');
            if (labelBarang) {
                labelBarang.innerHTML = 'Foto barang jaminan ' + jenisBarang + ' <span class="text-danger">*</span>';
            }

            var tgl = document.getElementById('tanggal_pencairan');
            if (tgl) {
                tgl.min = b.getAttribute('data-tgl-pengajuan') || '';
                tgl.value = tgl.max || tgl.value;
                tgl.dispatchEvent(new Event('change'));
            }
            var n = b.getAttribute('data-cicilan') || '0';
            var sd = document.getElementById('sudah_dibayar');
            if (sd) { sd.max = n; sd.value = 0; }
            var info = document.getElementById('info_dimuka');
            if (info) {
                info.textContent = 'Isi 0 bila belum ada cicilan yang dibayar. Maksimal ' + n +
                    ' (' + ({mingguan: 'mingguan', dua_mingguan: '2 minggu sekali', bulanan: 'bulanan'}[b.getAttribute('data-frekuensi')] || 'bulanan') + ').';
            }

            ['foto_nasabah', 'foto_barang_jaminan', 'foto_nasabah_barang'].forEach(function (id) {
                var el = document.getElementById(id);
                if (el) el.value = '';
                setPreview(id, false);
            });
            return true;
        }

        function bukaModalPencairan(b) {
            if (!isiFormPencairanDariTombol(b)) return;
            var menu = b.closest('.dropdown-menu');
            var wrap = (menu && menu._uiHomeParent) || b.closest('.ui-action-menu');
            var toggle = wrap && wrap.querySelector('[data-bs-toggle="dropdown"]');
            if (toggle && window.bootstrap && bootstrap.Dropdown) {
                var dd = bootstrap.Dropdown.getInstance(toggle);
                if (dd) dd.hide();
            }
            window.setTimeout(function () {
                bersihkanBackdropPencairan();
                if (!(window.bootstrap && bootstrap.Modal)) return;
                bootstrap.Modal.getOrCreateInstance(pencairanModal, {
                    backdrop: true,
                    keyboard: true,
                    focus: true
                }).show(b);
            }, 40);
        }

        pencairanModal.addEventListener('show.bs.modal', function (event) {
            if (event.relatedTarget) isiFormPencairanDariTombol(event.relatedTarget);
        });

        document.addEventListener('click', function (e) {
            var b = e.target && e.target.closest && e.target.closest('[data-ui-modal="#pencairanModal"]');
            if (!b || !b.closest('.dropdown-menu')) return;
            e.preventDefault();
            e.stopPropagation();
            if (e.stopImmediatePropagation) e.stopImmediatePropagation();
            bukaModalPencairan(b);
        }, true);

        var tglEl = document.getElementById('tanggal_pencairan');
        if (tglEl) {
            tglEl.addEventListener('change', function () {
                var mundur = this.value && this.max && this.value < this.max;
                var blok = document.getElementById('blok_dimuka');
                if (blok) blok.classList.toggle('d-none', !mundur);
                if (!mundur) {
                    var sd = document.getElementById('sudah_dibayar');
                    if (sd) sd.value = 0;
                }
            });
        }
        ['foto_nasabah', 'foto_barang_jaminan', 'foto_nasabah_barang'].forEach(function (id) {
            var el = document.getElementById(id);
            if (!el) return;
            el.addEventListener('change', function () { setPreview(id, true); });
        });

        var signaturePads = {};
        ['nasabah', 'teller', 'saksi_1', 'saksi_2'].forEach(function (key) {
            var c = document.getElementById('canvas_' + key);
            if (!c || !c.getContext) return;
            var ctx = c.getContext('2d');
            var drawing = false;
            var dirty = false;
            function resize() {
                var r = c.getBoundingClientRect();
                var ratio = window.devicePixelRatio || 1;
                var w = Math.max(1, Math.round(r.width * ratio));
                var h = Math.max(1, Math.round(r.height * ratio));
                if (c.width === w && c.height === h) return;
                var before = dirty ? c.toDataURL('image/png') : null;
                c.width = w; c.height = h;
                ctx = c.getContext('2d');
                ctx.lineWidth = 2.5 * ratio;
                ctx.lineCap = 'round';
                ctx.lineJoin = 'round';
                ctx.strokeStyle = '#183f49';
                if (before) {
                    var image = new Image();
                    image.onload = function () { ctx.drawImage(image, 0, 0, c.width, c.height); };
                    image.src = before;
                }
            }
            function pos(e) {
                var r = c.getBoundingClientRect();
                return [(e.clientX - r.left) * c.width / r.width, (e.clientY - r.top) * c.height / r.height];
            }
            function setStatus(ready) {
                var s = document.getElementById('status_' + key);
                var w = c.closest('.signature-canvas-wrap');
                if (!s) return;
                s.classList.toggle('is-ready', ready);
                s.innerHTML = ready ? '<i class="ti ti-circle-check"></i> Siap' : '<i class="ti ti-signature"></i> Belum';
                if (w) w.classList.toggle('has-signature', ready);
            }
            function simpanTtdKeForm() {
                var hid = document.getElementById('ttd_' + key);
                if (hid) hid.value = dirty ? c.toDataURL('image/png') : '';
            }
            function stopTtd(e) {
                drawing = false;
                simpanTtdKeForm();
                if (c.hasPointerCapture && c.hasPointerCapture(e.pointerId)) c.releasePointerCapture(e.pointerId);
            }
            resize();
            c.addEventListener('pointerdown', function (e) {
                if (!e.isPrimary) return;
                e.preventDefault();
                resize();
                drawing = true;
                dirty = true;
                var p = pos(e);
                ctx.beginPath();
                ctx.moveTo(p[0], p[1]);
                ctx.lineTo(p[0] + 0.01, p[1] + 0.01);
                ctx.stroke();
                c.setPointerCapture(e.pointerId);
                setStatus(true);
            }, { passive: false });
            c.addEventListener('pointermove', function (e) {
                if (!drawing || !e.isPrimary) return;
                e.preventDefault();
                var p = pos(e);
                ctx.lineTo(p[0], p[1]);
                ctx.stroke();
            }, { passive: false });
            c.addEventListener('pointerup', stopTtd);
            c.addEventListener('pointercancel', stopTtd);
            c.addEventListener('lostpointercapture', stopTtd);
            if (window.ResizeObserver) new ResizeObserver(function () { if (!drawing) resize(); }).observe(c);
            signaturePads[key] = {
                canvas: c,
                resize: resize,
                isDirty: function () { return dirty; },
                clear: function () {
                    ctx.clearRect(0, 0, c.width, c.height);
                    dirty = false;
                    var hid = document.getElementById('ttd_' + key);
                    if (hid) hid.value = '';
                    setStatus(false);
                }
            };
        });
        document.querySelectorAll('.clear-signature').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var pad = signaturePads[this.dataset.key];
                if (pad) pad.clear();
            });
        });
        pencairanModal.addEventListener('shown.bs.modal', function () {
            Object.keys(signaturePads).forEach(function (k) {
                signaturePads[k].resize();
                signaturePads[k].clear();
            });
        });

        formPencairan.addEventListener('submit', async function (event) {
            event.preventDefault();
            var form = this;
            var tombol = document.getElementById('btnCairkan');
            if (!tombol || tombol.disabled) return;
            tombol.disabled = true;
            tombol.innerHTML = '<i class="ti ti-loader-2 ti-spin me-1"></i> Mengompres foto (maks. 1 MB)...';
            try {
                if (window.SimkeuKompresFoto && SimkeuKompresFoto.kompresSemuaInputDiForm) {
                    await SimkeuKompresFoto.kompresSemuaInputDiForm(form);
                } else {
                    throw new Error('Modul kompresi foto belum dimuat. Refresh halaman lalu coba lagi.');
                }
                var keys = Object.keys(signaturePads);
                if (keys.length < 4 || !keys.every(function (k) { return signaturePads[k].isDirty(); })) {
                    throw new Error('TTD nasabah, Teller, dan kedua saksi wajib dibuat langsung pada layar.');
                }
                keys.forEach(function (k) {
                    document.getElementById('ttd_' + k).value = signaturePads[k].canvas.toDataURL('image/png');
                });
                var data = new FormData(form);
                var batas = (window.SimkeuKompresFoto && SimkeuKompresFoto.BATAS_FOTO) || (1024 * 1024);
                for (var i = 0; i < keys.length; i++) {
                    var key = keys[i];
                    data.delete('ttd_' + key);
                    var blob = await new Promise(function (resolve) { signaturePads[key].canvas.toBlob(resolve, 'image/png'); });
                    if (!blob || blob.size > batas) throw new Error('TTD gagal dibuat atau melebihi 1 MB.');
                    data.append('ttd_' + key + '_file', blob, 'ttd-' + key + '.png');
                }
                // Double-check semua file gambar di FormData ≤ 1 MB
                data.forEach(function (val, name) {
                    if (val && typeof val === 'object' && typeof val.size === 'number' && val.type && /^image\//i.test(val.type) && val.size > batas) {
                        throw new Error('File "' + (val.name || name) + '" masih melebihi 1 MB.');
                    }
                });
                tombol.innerHTML = '<i class="ti ti-loader-2 ti-spin me-1"></i> Mencairkan...';
                var postUrl = form.getAttribute('action') || window.location.href;
                var response = await fetch(postUrl, { method: 'POST', body: data, credentials: 'same-origin' });
                if (!response.ok) {
                    throw new Error(response.status === 403
                        ? 'Server menolak pencairan (403). Periksa status pengajuan sebelum mencoba lagi.'
                        : 'Respons server bermasalah (HTTP ' + response.status + ').');
                }
                if (!response.redirected) throw new Error('Respons pencairan tidak sesuai. Periksa status pengajuan dan sesi login.');
                window.location.assign(response.url);
            } catch (err) {
                alert(err instanceof TypeError ? 'Koneksi terputus. Periksa status pengajuan sebelum mencoba lagi.' : err.message);
                tombol.disabled = false;
                tombol.innerHTML = '<i class="ti ti-circle-check me-1"></i> Cairkan pinjaman';
            }
        });

        if (detailModal) {
            detailModal.addEventListener('show.bs.modal', function (event) {
                var button = event.relatedTarget;
                if (!button) return;
                document.getElementById('detail_kode').textContent = button.getAttribute('data-kode');
                document.getElementById('detail_kode_pinjaman').textContent = button.getAttribute('data-kode_pinjaman');
                document.getElementById('detail_nama').textContent = button.getAttribute('data-nama');
                document.getElementById('detail_kode_anggota').textContent = button.getAttribute('data-kode_anggota');
                document.getElementById('detail_jenis').textContent = button.getAttribute('data-jenis');
                document.getElementById('detail_jumlah').textContent = 'Rp ' + Number(button.getAttribute('data-jumlah')).toLocaleString('id-ID');
                document.getElementById('detail_tenor').textContent = button.getAttribute('data-tenor');
                document.getElementById('detail_bunga').textContent = button.getAttribute('data-bunga');
                document.getElementById('detail_admin').textContent = button.getAttribute('data-admin');
                document.getElementById('detail_teller').textContent = button.getAttribute('data-teller');
                document.getElementById('detail_tanggal_pencairan').textContent = button.getAttribute('data-tanggal_pencairan');
                var status = button.getAttribute('data-status');
                var statusLabel = button.getAttribute('data-status-label') || status;
                var statusClass = { menunggu: 'warning', disetujui: 'success', dicairkan: 'info', ditolak: 'danger', develop: 'success' };
                document.getElementById('detail_status').innerHTML = '<span class="badge bg-' + (statusClass[status] || 'secondary') + '">' + statusLabel + '</span>';
                document.getElementById('detail_tujuan').textContent = button.getAttribute('data-tujuan') || '-';
                document.getElementById('detail_tanggal').textContent = button.getAttribute('data-tanggal');
                document.getElementById('detail_catatan').textContent = button.getAttribute('data-catatan') || '-';
            });
        }
    })();
    </script>
</body>
</html>

