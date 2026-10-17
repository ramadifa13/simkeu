<?php
// Start session jika belum
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Include config dan auth dengan path yang benar
require_once '../config/config.php';
require_once '../includes/auth.php';

// Check if user is logged in
if (!isLoggedIn()) {
    header("Location: ../login.php");
    exit();
}

// Pastikan hanya teller yang bisa akses
if (!isTeller()) {
    $_SESSION['error'] = "Anda tidak memiliki akses ke halaman ini";
    header("Location: ../dashboard.php");
    exit();
}

// Handle form submission untuk pengajuan baru
if ($_POST && isset($_POST['ajukan_pinjaman'])) {
    $anggota_id = $_POST['anggota_id'];
    $jenis_pinjaman_id = $_POST['jenis_pinjaman_id'];
    $jumlah_pinjaman = $_POST['jumlah_pinjaman'];
    $tenor = (int) $_POST['tenor'];
    $frekuensi = isset($_POST['frekuensi']) ? $_POST['frekuensi'] : '';
    $tujuan_pinjaman = $_POST['tujuan_pinjaman'];
    $user_id = $_SESSION['user_id'];

    try {
        $db->beginTransaction();

        // Get jenis pinjaman info for validation
        $query_jenis = "SELECT maksimal_pinjaman, tenor_max, frekuensi_diizinkan, memerlukan_jaminan FROM jenis_pinjaman WHERE id = :jenis_id AND status = 'aktif'";
        $stmt_jenis = $db->prepare($query_jenis);
        $stmt_jenis->bindParam(':jenis_id', $jenis_pinjaman_id);
        $stmt_jenis->execute();
        $jenis_info = $stmt_jenis->fetch(PDO::FETCH_ASSOC);
        
        if (!$jenis_info) {
            throw new Exception("Jenis pinjaman tidak valid atau tidak aktif");
        }
        if (!empty($jenis_info['memerlukan_jaminan'])) {
            // Gadai selalu menyediakan ketiga frekuensi dan tenor 1-6 bulan.
            // Ini juga menjaga jenis gadai lama tetap kompatibel tanpa disimpan ulang.
            $jenis_info['frekuensi_diizinkan'] = 'bulanan,mingguan,dua_mingguan';
        }
        
        $jumlah_pinjaman = parseRupiah($jumlah_pinjaman);
        if ($jumlah_pinjaman === false || $jumlah_pinjaman <= 0) {
            throw new Exception("Jumlah pinjaman harus berupa angka rupiah penuh lebih dari 0");
        }

        // Validate maksimal pinjaman
        if ($jumlah_pinjaman > $jenis_info['maksimal_pinjaman']) {
            throw new Exception("Jumlah pinjaman melebihi batas maksimal. Maksimal: " . formatRupiah($jenis_info['maksimal_pinjaman']));
        }
        
        // Validate tenor & bunga: hanya tenor yang punya tarif flat di jenis pinjaman ini
        $tarif = !empty($jenis_info['memerlukan_jaminan'])
            ? [1 => 15, 2 => 15, 3 => 15, 4 => 15, 5 => 15, 6 => 15]
            : getTarifJenisPinjaman($db, $jenis_pinjaman_id);
        if (!isset($tarif[$tenor])) {
            throw new Exception("Tenor $tenor bulan tidak tersedia untuk jenis pinjaman ini");
        }
        $bunga = $tarif[$tenor];

        // Validate frekuensi
        if (!in_array($frekuensi, explode(',', $jenis_info['frekuensi_diizinkan']), true)) {
            throw new Exception("Frekuensi angsuran tidak diizinkan untuk jenis pinjaman ini");
        }
        if (empty($jenis_info['memerlukan_jaminan']) && $frekuensi === 'dua_mingguan' && $tenor !== 6) {
            throw new Exception("Angsuran 2 minggu sekali tanpa jaminan hanya tersedia untuk tenor 6 bulan");
        }
        if (empty($jenis_info['memerlukan_jaminan']) && $frekuensi === 'dua_mingguan') {
            if (!in_array((int) $jumlah_pinjaman, [2000000, 3000000], true)) {
                throw new Exception("Tenor 6 bulan / 2 minggu sekali saat ini hanya tersedia untuk pinjaman Rp 2 juta atau Rp 3 juta");
            }
            // Sesuai tabel resmi: total kembali 145% dari pokok.
            $bunga = 45;
        }

        // Untuk gadai, tarif tenor adalah bunga dasar (umumnya 15%). Tambahan
        // Rp10/15/20 ribu per minggu dimasukkan ke bunga efektif yang disimpan.
        if (!empty($jenis_info['memerlukan_jaminan'])) {
            if ($tenor < 1 || $tenor > 6) {
                throw new Exception("Tenor pinjaman gadai harus antara 1 sampai 6 bulan");
            }
            $hitung_gadai = hitungPinjamanGadai($jumlah_pinjaman, $bunga, $tenor, $frekuensi);
            $bunga = $hitung_gadai['bunga_persen'];
        }

        $jaminan = null;
        if (!empty($jenis_info['memerlukan_jaminan'])) {
            $kategori = trim($_POST['kategori_jaminan'] ?? '');
            $wajib = function ($nama, $label) {
                $nilai = trim($_POST[$nama] ?? '');
                if ($nilai === '') throw new Exception("$label wajib diisi");
                return $nilai;
            };
            if ($kategori === 'hp') {
                $jenis = $wajib('hp_jenis', 'Jenis HP');
                $merk = $wajib('hp_merk_tipe', 'Merk/tipe HP');
                $warna = $wajib('hp_warna', 'Warna HP');
                $imei = $wajib('hp_imei', 'IMEI');
                $kelengkapan = $wajib('hp_kelengkapan', 'Kelengkapan HP');
                $kondisi = $wajib('hp_kondisi', 'Kondisi HP');
                $jenis_barang = 'HP - ' . $jenis;
                $identitas_barang = 'IMEI: ' . $imei;
                $deskripsi_barang = "Merk/tipe: $merk\nWarna: $warna\nKelengkapan: $kelengkapan\nKondisi: $kondisi";
            } elseif ($kategori === 'sepeda_motor') {
                $jenis = $wajib('motor_jenis_kendaraan', 'Jenis kendaraan');
                $merk = $wajib('motor_merk_tipe', 'Merk/tipe kendaraan');
                $tahun = $wajib('motor_tahun', 'Tahun kendaraan');
                $nopol = $wajib('motor_nopol', 'Nomor polisi');
                $rangka = $wajib('motor_no_rangka', 'Nomor rangka');
                $mesin = $wajib('motor_no_mesin', 'Nomor mesin');
                $warna = $wajib('motor_warna', 'Warna kendaraan');
                $atas_nama = $wajib('motor_atas_nama_bpkb', 'Atas nama BPKB');
                $no_bpkb = $wajib('motor_no_bpkb', 'Nomor BPKB');
                $jenis_barang = 'Sepeda Motor - ' . $jenis;
                $identitas_barang = "Nopol: $nopol; BPKB: $no_bpkb";
                $deskripsi_barang = "Merk/tipe: $merk\nTahun: $tahun\nNo. rangka: $rangka\nNo. mesin: $mesin\nWarna: $warna\nAtas nama BPKB: $atas_nama";
            } elseif ($kategori === 'emas') {
                $jenis = $wajib('emas_jenis_barang', 'Jenis barang emas');
                $berat = $wajib('emas_berat', 'Berat emas');
                $kadar = $wajib('emas_kadar', 'Kadar emas');
                $ciri = $wajib('emas_bentuk_ciri', 'Bentuk/ciri emas');
                $surat = $wajib('emas_surat_nota', 'Surat/nota emas');
                $jenis_barang = 'Emas - ' . $jenis;
                $identitas_barang = 'Surat/nota: ' . $surat;
                $deskripsi_barang = "Berat: $berat\nKadar: $kadar\nBentuk/ciri: $ciri";
            } else {
                throw new Exception("Pilih kategori jaminan: HP, sepeda motor, atau emas");
            }
            $jaminan = [
                'jenis_barang' => $jenis_barang,
                'identitas_barang' => $identitas_barang,
                'deskripsi_barang' => $deskripsi_barang,
                'nilai_taksiran' => parseRupiah($_POST['nilai_taksiran'] ?? ''),
            ];
            if ($jaminan['nilai_taksiran'] === false || (float) $jaminan['nilai_taksiran'] <= 0) {
                throw new Exception("Nilai taksiran jaminan harus berupa angka lebih dari 0");
            }
            if ((float) $jumlah_pinjaman > (float) $jaminan['nilai_taksiran']) {
                throw new Exception("Jumlah pinjaman tidak boleh melebihi nilai taksiran barang");
            }
        }

        $hari_angsuran = !empty($jenis_info['memerlukan_jaminan'])
            ? null
            : (isset($_POST['hari_angsuran']) ? (int) $_POST['hari_angsuran'] : 0);
        if (empty($jenis_info['memerlukan_jaminan']) && ($hari_angsuran < 1 || $hari_angsuran > 7)) {
            throw new Exception("Hari angsuran wajib dipilih (Senin - Minggu)");
        }

        // Tanggal pengajuan (boleh lampau untuk data lama, tidak boleh masa depan)
        $tgl_ajuan = isset($_POST['tanggal_pengajuan']) ? trim($_POST['tanggal_pengajuan']) : date('Y-m-d');
        $dt = DateTime::createFromFormat('Y-m-d', $tgl_ajuan);
        if (!$dt || $dt->format('Y-m-d') !== $tgl_ajuan) {
            throw new Exception("Tanggal pengajuan tidak valid");
        }
        if ($tgl_ajuan > date('Y-m-d')) {
            throw new Exception("Tanggal pengajuan tidak boleh di masa depan");
        }
        $waktu_ajuan = $tgl_ajuan . ' ' . date('H:i:s');

        // Generate kode pengajuan
        // Maks. 20 karakter (= panjang kolom); format lama 22 karakter ditolak MySQL strict mode
        $kode_pengajuan = 'PJG-' . date('ymdHis') . '-' . str_pad(mt_rand(1, 999), 3, '0', STR_PAD_LEFT);

        // Insert pengajuan (skema flat)
        $query = "INSERT INTO pengajuan_pinjaman (kode_pengajuan, anggota_id, jenis_pinjaman_id,
                  jumlah_pinjaman, tenor, bunga, skema_bunga, frekuensi, tujuan_pinjaman, user_id, tanggal_pengajuan, hari_angsuran)
                  VALUES (:kode_pengajuan, :anggota_id, :jenis_pinjaman_id,
                  :jumlah_pinjaman, :tenor, :bunga, 'flat', :frekuensi, :tujuan_pinjaman, :user_id, :tanggal_pengajuan, :hari_angsuran)";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':tanggal_pengajuan', $waktu_ajuan);
        $stmt->bindValue(':hari_angsuran', $hari_angsuran, $hari_angsuran === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindParam(':kode_pengajuan', $kode_pengajuan);
        $stmt->bindParam(':anggota_id', $anggota_id);
        $stmt->bindParam(':jenis_pinjaman_id', $jenis_pinjaman_id);
        $stmt->bindParam(':jumlah_pinjaman', $jumlah_pinjaman);
        $stmt->bindParam(':tenor', $tenor);
        $stmt->bindParam(':bunga', $bunga);
        $stmt->bindParam(':frekuensi', $frekuensi);
        $stmt->bindParam(':tujuan_pinjaman', $tujuan_pinjaman);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();
        $pengajuan_id = $db->lastInsertId();
        if ($jaminan !== null) {
            $stmt_jaminan = $db->prepare("INSERT INTO jaminan_pinjaman
                (pengajuan_id, jenis_barang, identitas_barang, deskripsi_barang, nilai_taksiran, status_jaminan)
                VALUES (?, ?, ?, ?, ?, 'dititipkan')");
            $stmt_jaminan->execute([$pengajuan_id, $jaminan['jenis_barang'], $jaminan['identitas_barang'],
                $jaminan['deskripsi_barang'], $jaminan['nilai_taksiran']]);
        }
        
        $db->commit();
        $_SESSION['success'] = "Pengajuan pinjaman berhasil dibuat. Kode: " . $kode_pengajuan . " - Menunggu persetujuan admin";
        header("Location: pengajuan.php");
        exit();
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $_SESSION['error'] = "Gagal mengajukan pinjaman: " . $e->getMessage();
    }
}

// Get data for dropdowns
$query_anggota = "SELECT * FROM anggota WHERE status = 'aktif' ORDER BY nama_lengkap";
$stmt_anggota = $db->prepare($query_anggota);
$stmt_anggota->execute();
$anggota_list = $stmt_anggota->fetchAll(PDO::FETCH_ASSOC);

$query_jenis = "SELECT * FROM jenis_pinjaman WHERE status = 'aktif' ORDER BY nama_jenis";
$stmt_jenis = $db->prepare($query_jenis);
$stmt_jenis->execute();
$jenis_pinjaman = [];
foreach ($stmt_jenis->fetchAll(PDO::FETCH_ASSOC) as $jenis) {
    // Jenis tanpa tarif flat (skema lama) tidak bisa diajukan sampai Admin mengisi tarifnya
    if (!empty($jenis['memerlukan_jaminan'])) {
        $jenis['tarif'] = [1 => 15, 2 => 15, 3 => 15, 4 => 15, 5 => 15, 6 => 15];
        $jenis['frekuensi_diizinkan'] = 'bulanan,mingguan,dua_mingguan';
    } else {
        $jenis['tarif'] = getTarifJenisPinjaman($db, $jenis['id']);
    }
    if (!empty($jenis['tarif'])) {
        $jenis_pinjaman[] = $jenis;
    }
}

// Get pengajuan yang dibuat oleh teller ini
$query_pengajuan = "SELECT 
                    pp.*, 
                    a.nama_lengkap, 
                    a.kode_anggota, 
                    jp.nama_jenis, 
                    jp.maksimal_pinjaman, 
                    u.nama_lengkap as admin_name,
                    pp.tanggal_pengajuan,
                    pp.tanggal_persetujuan
                    FROM pengajuan_pinjaman pp
                    JOIN anggota a ON pp.anggota_id = a.id
                    JOIN jenis_pinjaman jp ON pp.jenis_pinjaman_id = jp.id
                    LEFT JOIN users u ON pp.admin_id = u.id
                    WHERE pp.user_id = :user_id
                    ORDER BY pp.tanggal_pengajuan DESC";

$stmt_pengajuan = $db->prepare($query_pengajuan);
$stmt_pengajuan->bindParam(':user_id', $_SESSION['user_id']);
$stmt_pengajuan->execute();
$pengajuan_list = $stmt_pengajuan->fetchAll(PDO::FETCH_ASSOC);

// Debug: Check if data is retrieved
error_log("Pengajuan found: " . count($pengajuan_list));
if (count($pengajuan_list) > 0) {
    error_log("First pengajuan: " . print_r($pengajuan_list[0], true));
}

// Get statistics untuk teller
$query_stats = "SELECT 
    COUNT(*) as total_pengajuan,
    COUNT(CASE WHEN status = 'menunggu' THEN 1 END) as menunggu,
    COUNT(CASE WHEN status = 'disetujui' THEN 1 END) as disetujui,
    COUNT(CASE WHEN status = 'ditolak' THEN 1 END) as ditolak
    FROM pengajuan_pinjaman 
    WHERE user_id = :user_id";
$stmt_stats = $db->prepare($query_stats);
$stmt_stats->bindParam(':user_id', $_SESSION['user_id']);
$stmt_stats->execute();
$stats = $stmt_stats->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pengajuan Pinjaman - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .stat-card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .table-actions {
            white-space: nowrap;
        }
        .status-badge {
            font-size: 0.75rem;
            padding: 0.5rem 0.75rem;
        }
        .form-card {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 1.5rem;
        }
        .calculation-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 10px;
        }
        .pengajuan-table th {
            background-color: #f8f9fa;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="container-fluid">
        <div class="row">
            <?php include '../includes/sidebar.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">
                        <i class="fas fa-file-alt me-2"></i>Pengajuan Pinjaman
                    </h1>
                </div>

                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i>
                        <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['error'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Statistics Cards -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card stat-card bg-primary text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h4><?php echo $stats['total_pengajuan']; ?></h4>
                                        <p>Total Pengajuan</p>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-file-alt fa-2x"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stat-card bg-warning text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h4><?php echo $stats['menunggu']; ?></h4>
                                        <p>Menunggu</p>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-clock fa-2x"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stat-card bg-success text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h4><?php echo $stats['disetujui']; ?></h4>
                                        <p>Disetujui</p>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-check-circle fa-2x"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stat-card bg-danger text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h4><?php echo $stats['ditolak']; ?></h4>
                                        <p>Ditolak</p>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-times-circle fa-2x"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <!-- Form Pengajuan -->
                    <div class="col-lg-6 mb-4">
                        <div class="card">
                            <div class="card-header bg-primary text-white">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-plus-circle me-2"></i>Form Pengajuan Pinjaman Baru
                                </h5>
                            </div>
                            <div class="card-body form-card">
                                <form method="POST" id="formPengajuan">
                                    <div class="mb-3">
                                        <label class="form-label">Pilih Anggota *</label>
                                        <select name="anggota_id" class="form-select" required id="anggotaSelect">
                                            <option value="">-- Pilih Anggota --</option>
                                            <?php foreach ($anggota_list as $anggota): ?>
                                            <option value="<?php echo $anggota['id']; ?>">
                                                <?php echo $anggota['kode_anggota'] . ' - ' . $anggota['nama_lengkap']; ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Jenis Pinjaman *</label>
                                        <select name="jenis_pinjaman_id" class="form-select" required id="jenisPinjamanSelect">
                                            <option value="">-- Pilih Jenis Pinjaman --</option>
                                            <?php foreach ($jenis_pinjaman as $jenis): ?>
                                            <option value="<?php echo $jenis['id']; ?>" 
                                                    data-maksimal="<?php echo $jenis['maksimal_pinjaman']; ?>"
                                                    data-tarif="<?php echo htmlspecialchars(json_encode((object) $jenis['tarif']), ENT_QUOTES); ?>"
                                                    data-frekuensi="<?php echo $jenis['frekuensi_diizinkan']; ?>"
                                                    data-jaminan="<?php echo !empty($jenis['memerlukan_jaminan']) ? '1' : '0'; ?>">
                                                <?php echo $jenis['nama_jenis']; ?>
                                                (Maks: <?php echo formatRupiah($jenis['maksimal_pinjaman']); ?> |
                                                Tenor: <?php echo implode('/', array_keys($jenis['tarif'])); ?> bln)
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Jumlah Pinjaman (Rp) *</label>
                                                <input type="number" name="jumlah_pinjaman" class="form-control" 
                                                       required step="1" min="1" id="jumlahPinjaman"
                                                       placeholder="Masukkan jumlah pinjaman">
                                                <small class="text-muted" id="maxInfo"></small>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Tenor (Bulan) *</label>
                                                <select name="tenor" class="form-select" required id="tenorSelect">
                                                    <option value="">-- Pilih jenis pinjaman dulu --</option>
                                                </select>
                                                <small class="text-muted" id="tenorInfo"></small>
                                            </div>
                                        </div>
                                    </div>

                                    <div id="jaminanFields" class="border rounded p-3 mb-3 bg-light" style="display:none">
                                        <h6><i class="fas fa-box me-1"></i> Data Barang Jaminan / Gadai</h6>
                                        <label class="form-label d-block">Pilih Jaminan *</label>
                                        <div class="mb-3">
                                            <div class="form-check form-check-inline"><input class="form-check-input kategori-jaminan" type="radio" name="kategori_jaminan" value="hp" id="jaminanHp"><label class="form-check-label" for="jaminanHp">HP</label></div>
                                            <div class="form-check form-check-inline"><input class="form-check-input kategori-jaminan" type="radio" name="kategori_jaminan" value="sepeda_motor" id="jaminanMotor"><label class="form-check-label" for="jaminanMotor">Sepeda motor</label></div>
                                            <div class="form-check form-check-inline"><input class="form-check-input kategori-jaminan" type="radio" name="kategori_jaminan" value="emas" id="jaminanEmas"><label class="form-check-label" for="jaminanEmas">Emas</label></div>
                                        </div>
                                        <div class="jaminan-detail" data-kategori="hp" style="display:none"><div class="row">
                                            <div class="col-md-6 mb-2"><label>Jenis *</label><input class="form-control detail-required" name="hp_jenis"></div><div class="col-md-6 mb-2"><label>Merk/tipe HP *</label><input class="form-control detail-required" name="hp_merk_tipe"></div>
                                            <div class="col-md-6 mb-2"><label>Warna *</label><input class="form-control detail-required" name="hp_warna"></div><div class="col-md-6 mb-2"><label>IMEI *</label><input class="form-control detail-required" name="hp_imei"></div>
                                            <div class="col-md-6 mb-2"><label>Kelengkapan *</label><input class="form-control detail-required" name="hp_kelengkapan"></div><div class="col-md-6 mb-2"><label>Kondisi *</label><input class="form-control detail-required" name="hp_kondisi"></div>
                                        </div></div>
                                        <div class="jaminan-detail" data-kategori="sepeda_motor" style="display:none"><div class="row">
                                            <div class="col-md-6 mb-2"><label>Jenis kendaraan *</label><input class="form-control detail-required" name="motor_jenis_kendaraan"></div><div class="col-md-6 mb-2"><label>Merk/tipe *</label><input class="form-control detail-required" name="motor_merk_tipe"></div>
                                            <div class="col-md-4 mb-2"><label>Tahun *</label><input type="number" min="1900" max="<?php echo date('Y') + 1; ?>" class="form-control detail-required" name="motor_tahun"></div><div class="col-md-4 mb-2"><label>Nopol *</label><input class="form-control detail-required" name="motor_nopol"></div><div class="col-md-4 mb-2"><label>Warna *</label><input class="form-control detail-required" name="motor_warna"></div>
                                            <div class="col-md-6 mb-2"><label>No. rangka *</label><input class="form-control detail-required" name="motor_no_rangka"></div><div class="col-md-6 mb-2"><label>No. mesin *</label><input class="form-control detail-required" name="motor_no_mesin"></div>
                                            <div class="col-md-6 mb-2"><label>Atas nama BPKB *</label><input class="form-control detail-required" name="motor_atas_nama_bpkb"></div><div class="col-md-6 mb-2"><label>No. BPKB *</label><input class="form-control detail-required" name="motor_no_bpkb"></div>
                                        </div></div>
                                        <div class="jaminan-detail" data-kategori="emas" style="display:none"><div class="row">
                                            <div class="col-md-6 mb-2"><label>Jenis barang *</label><input class="form-control detail-required" name="emas_jenis_barang"></div><div class="col-md-6 mb-2"><label>Berat *</label><input class="form-control detail-required" name="emas_berat" placeholder="Contoh: 10 gram"></div>
                                            <div class="col-md-6 mb-2"><label>Kadar *</label><input class="form-control detail-required" name="emas_kadar" placeholder="Contoh: 22 karat"></div><div class="col-md-6 mb-2"><label>Bentuk/ciri *</label><input class="form-control detail-required" name="emas_bentuk_ciri"></div><div class="col-12 mb-2"><label>Surat/nota *</label><input class="form-control detail-required" name="emas_surat_nota"></div>
                                        </div></div>
                                        <div class="mt-2"><label>Nilai Taksiran (Rp) *</label><input type="number" name="nilai_taksiran" class="form-control jaminan-common-required" min="1" step="1"></div>
                                        <small class="text-muted">Jumlah pinjaman tidak boleh melebihi nilai taksiran.</small>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label d-block">Frekuensi Angsuran *</label>
                                        <div class="form-check form-check-inline">
                                            <input class="form-check-input" type="radio" name="frekuensi" id="frekBulanan" value="bulanan" required>
                                            <label class="form-check-label" for="frekBulanan">Bulanan</label>
                                        </div>
                                        <div class="form-check form-check-inline">
                                            <input class="form-check-input" type="radio" name="frekuensi" id="frekMingguan" value="mingguan" required>
                                            <label class="form-check-label" for="frekMingguan">Mingguan</label>
                                        </div>
                                        <div class="form-check form-check-inline">
                                            <input class="form-check-input" type="radio" name="frekuensi" id="frekDuaMingguan" value="dua_mingguan" required>
                                            <label class="form-check-label" for="frekDuaMingguan">2 minggu sekali</label>
                                        </div>
                                    </div>

                                    <div class="mb-3" id="hariAngsuranGroup">
                                        <label class="form-label">Hari Angsuran *</label>
                                        <select name="hari_angsuran" class="form-select" required>
                                            <option value="">-- Pilih hari --</option>
                                            <?php foreach (daftarHariAngsuran() as $no => $nama_hari): ?>
                                            <option value="<?php echo $no; ?>"><?php echo $nama_hari; ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="text-muted">Hari nasabah membayar angsuran; dipakai untuk memfilter menu Angsuran.</small>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Tanggal Pengajuan *</label>
                                        <input type="date" name="tanggal_pengajuan" class="form-control" required
                                               value="<?php echo date('Y-m-d'); ?>" max="<?php echo date('Y-m-d'); ?>">
                                        <small class="text-muted">Isi tanggal lama bila ini data nasabah yang sudah mengajukan sebelumnya.</small>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Tujuan Pinjaman *</label>
                                        <textarea name="tujuan_pinjaman" class="form-control" rows="3" required
                                                  placeholder="Jelaskan tujuan penggunaan pinjaman..."></textarea>
                                    </div>
                                    
                                    <!-- Informasi Kalkulasi -->
                                    <div class="calculation-card p-3 mb-3" id="calculationInfo" style="display: none;">
                                        <h6 class="text-white mb-3">Perhitungan Pinjaman</h6>
                                        <div class="row text-center">
                                            <div class="col-4">
                                                <small>Bunga (flat)</small>
                                                <div class="h5 fw-bold" id="displayBunga">0%</div>
                                            </div>
                                            <div class="col-4">
                                                <small>Total Pinjaman</small>
                                                <div class="h5 fw-bold" id="displayTotal">Rp 0</div>
                                            </div>
                                            <div class="col-4">
                                                <small id="labelAngsuran">Angsuran/Bulan</small>
                                                <div class="h5 fw-bold" id="displayAngsuran">Rp 0</div>
                                                <small id="displayCicilan"></small>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="alert alert-info">
                                        <small>
                                            <i class="fas fa-info-circle me-1"></i>
                                            Pengajuan pinjaman akan dikirim untuk persetujuan admin. Proses persetujuan membutuhkan waktu 1-2 hari kerja.
                                        </small>
                                    </div>
                                    
                                    <div class="d-grid gap-2">
                                        <button type="submit" name="ajukan_pinjaman" class="btn btn-primary btn-lg">
                                            <i class="fas fa-paper-plane me-2"></i> Ajukan Pinjaman
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Daftar Pengajuan -->
                    <div class="col-lg-6">
                        <div class="card">
                            <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-history me-2"></i>Riwayat Pengajuan Saya
                                </h5>
                                <span class="badge bg-light text-dark">
                                    <?php echo count($pengajuan_list); ?> pengajuan
                                </span>
                            </div>
                            <div class="card-body">
                                <?php if (empty($pengajuan_list)): ?>
                                <div class="text-center py-4">
                                    <i class="fas fa-file-alt fa-3x text-muted mb-3"></i>
                                    <h5 class="text-muted">Belum ada pengajuan</h5>
                                    <p class="text-muted">Ajukan pinjaman pertama untuk anggota</p>
                                </div>
                                <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-sm pengajuan-table">
                                        <thead>
                                            <tr>
                                                <th>Kode</th>
                                                <th>Anggota</th>
                                                <th>Jenis</th>
                                                <th>Jumlah</th>
                                                <th>Status</th>
                                                <th>Aksi</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($pengajuan_list as $pengajuan): ?>
                                            <tr>
                                                <td>
                                                    <small class="fw-bold"><?php echo $pengajuan['kode_pengajuan']; ?></small>
                                                    <br>
                                                    <small class="text-muted">
                                                        <?php echo date('d/m/Y', strtotime($pengajuan['tanggal_pengajuan'])); ?>
                                                    </small>
                                                </td>
                                                <td>
                                                    <div class="fw-semibold"><?php echo $pengajuan['nama_lengkap']; ?></div>
                                                    <small class="text-muted"><?php echo $pengajuan['kode_anggota']; ?></small>
                                                </td>
                                                <td>
                                                    <small><?php echo $pengajuan['nama_jenis']; ?></small>
                                                    <br>
                                                    <small class="text-muted"><?php echo $pengajuan['tenor']; ?> bln · <?php echo labelHariAngsuran(isset($pengajuan['hari_angsuran']) ? $pengajuan['hari_angsuran'] : null); ?></small>
                                                </td>
                                                <td>
                                                    <div class="fw-bold text-primary"><?php echo formatRupiah($pengajuan['jumlah_pinjaman']); ?></div>
                                                </td>
                                                <td>
                                                    <?php
                                                    $status_class = [
                                                        'menunggu' => 'warning',
                                                        'disetujui' => 'success',
                                                        'ditolak' => 'danger',
                                                        'dicairkan' => 'info'
                                                    ];
                                                    ?>
                                                    <span class="badge bg-<?php echo $status_class[$pengajuan['status']]; ?> status-badge">
                                                        <?php echo ucfirst($pengajuan['status']); ?>
                                                    </span>
                                                    <?php if ($pengajuan['admin_name']): ?>
                                                        <br>
                                                        <small class="text-muted">Oleh: <?php echo $pengajuan['admin_name']; ?></small>
                                                    <?php endif; ?>
                                                    <?php if ($pengajuan['tanggal_persetujuan']): ?>
                                                        <br>
                                                        <small class="text-muted">
                                                            <?php echo date('d/m/Y', strtotime($pengajuan['tanggal_persetujuan'])); ?>
                                                        </small>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="table-actions">
                                                    <button class="btn btn-sm btn-info" data-bs-toggle="modal" 
                                                            data-bs-target="#detailModal" 
                                                            data-kode="<?php echo $pengajuan['kode_pengajuan']; ?>"
                                                            data-nama="<?php echo $pengajuan['nama_lengkap']; ?>"
                                                            data-kode_anggota="<?php echo $pengajuan['kode_anggota']; ?>"
                                                            data-jenis="<?php echo $pengajuan['nama_jenis']; ?>"
                                                            data-jumlah="<?php echo $pengajuan['jumlah_pinjaman']; ?>"
                                                            data-tenor="<?php echo $pengajuan['tenor'] . ' bulan, ' . ringkasanAngsuran(hitungDariPinjaman($pengajuan)); ?>"
                                                            data-bunga="<?php echo labelBunga($pengajuan['bunga'], $pengajuan['skema_bunga']); ?>"
                                                            data-tujuan="<?php echo $pengajuan['tujuan_pinjaman']; ?>"
                                                            data-status="<?php echo $pengajuan['status']; ?>"
                                                            data-tanggal="<?php echo date('d/m/Y H:i', strtotime($pengajuan['tanggal_pengajuan'])); ?>"
                                                            data-catatan="<?php echo $pengajuan['catatan_admin']; ?>"
                                                            data-admin="<?php echo $pengajuan['admin_name']; ?>"
                                                            data-tanggal_persetujuan="<?php echo $pengajuan['tanggal_persetujuan'] ? date('d/m/Y H:i', strtotime($pengajuan['tanggal_persetujuan'])) : ''; ?>"
                                                            title="Detail">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Detail Modal -->
    <div class="modal fade" id="detailModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Detail Pengajuan Pinjaman</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <table class="table table-borderless">
                                <tr>
                                    <th width="40%">Kode Pengajuan</th>
                                    <td id="detail_kode"></td>
                                </tr>
                                <tr>
                                    <th>Nama Anggota</th>
                                    <td id="detail_nama"></td>
                                </tr>
                                <tr>
                                    <th>Kode Anggota</th>
                                    <td id="detail_kode_anggota"></td>
                                </tr>
                                <tr>
                                    <th>Jenis Pinjaman</th>
                                    <td id="detail_jenis"></td>
                                </tr>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <table class="table table-borderless">
                                <tr>
                                    <th width="40%">Jumlah Pinjaman</th>
                                    <td id="detail_jumlah" class="fw-bold text-primary"></td>
                                </tr>
                                <tr>
                                    <th>Tenor</th>
                                    <td id="detail_tenor"></td>
                                </tr>
                                <tr>
                                    <th>Bunga</th>
                                    <td id="detail_bunga"></td>
                                </tr>
                                <tr>
                                    <th>Status</th>
                                    <td id="detail_status"></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-12">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Tujuan Pinjaman</label>
                                <div id="detail_tujuan" class="p-3 bg-light rounded"></div>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Tanggal Pengajuan</label>
                                <div id="detail_tanggal" class="p-2 bg-light rounded"></div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold">Diajukan Oleh</label>
                                <div class="p-2 bg-light rounded"><?php echo $_SESSION['nama_lengkap']; ?> (Teller)</div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <?php if ($pengajuan['admin_name']): ?>
                            <div class="mb-3">
                                <label class="form-label fw-bold">Disetujui/Ditolak Oleh</label>
                                <div id="detail_admin" class="p-2 bg-light rounded"></div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold">Tanggal Persetujuan</label>
                                <div id="detail_tanggal_persetujuan" class="p-2 bg-light rounded"></div>
                            </div>
                            <?php endif; ?>
                            <div class="mb-3">
                                <label class="form-label fw-bold">Catatan Admin</label>
                                <div id="detail_catatan" class="p-2 bg-light rounded"></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/pinjaman.js?v=20261017g"></script>
    <script>
        function getTarifTerpilih() {
            const opt = document.getElementById('jenisPinjamanSelect').selectedOptions[0];
            return opt && opt.value ? JSON.parse(opt.getAttribute('data-tarif') || '{}') : {};
        }

        function getFrekuensiTerpilih() {
            const r = document.querySelector('input[name="frekuensi"]:checked');
            return r ? r.value : '';
        }

        // Update informasi jenis pinjaman: tenor dari tarif, frekuensi yang diizinkan
        document.getElementById('jenisPinjamanSelect').addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const tenorSelect = document.getElementById('tenorSelect');
            tenorSelect.innerHTML = '<option value="">-- Pilih Tenor --</option>';
            if (!selectedOption.value) {
                document.getElementById('jaminanFields').style.display = 'none';
                document.querySelectorAll('.kategori-jaminan, .jaminan-common-required, .detail-required').forEach(function (el) { el.required = false; });
                document.getElementById('hariAngsuranGroup').style.display = '';
                document.getElementById('hariAngsuranGroup').querySelector('select').required = true;
                updateCalculation();
                return;
            }
            const maksimal = selectedOption.getAttribute('data-maksimal');
            const tarif = getTarifTerpilih();
            const frekuensi = (selectedOption.getAttribute('data-frekuensi') || 'bulanan').split(',');

            document.getElementById('maxInfo').textContent = 'Maksimal: Rp ' + parseInt(maksimal).toLocaleString('id-ID');
            document.getElementById('tenorInfo').textContent = 'Tenor tersedia: ' + Object.keys(tarif).join(', ') + ' bulan';
            document.getElementById('jumlahPinjaman').max = parseInt(maksimal);

            Object.keys(tarif).forEach(function (t) {
                tenorSelect.innerHTML += '<option value="' + t + '">' + t + ' bulan (bunga ' + tarif[t] + '%)</option>';
            });

            const ids = {bulanan: 'frekBulanan', mingguan: 'frekMingguan', dua_mingguan: 'frekDuaMingguan'};
            ['bulanan', 'mingguan', 'dua_mingguan'].forEach(function (f) {
                const radio = document.getElementById(ids[f]);
                const allowed = frekuensi.indexOf(f) !== -1;
                radio.disabled = !allowed;
                radio.parentElement.style.display = allowed ? '' : 'none';
                if (!allowed) radio.checked = false;
            });
            if (!getFrekuensiTerpilih()) {
                document.getElementById(ids[frekuensi[0]] || 'frekBulanan').checked = true;
            }

            const wajibJaminan = selectedOption.getAttribute('data-jaminan') === '1';
            document.getElementById('jaminanFields').style.display = wajibJaminan ? '' : 'none';
            document.querySelectorAll('.kategori-jaminan, .jaminan-common-required').forEach(function (el) { el.required = wajibJaminan; });
            if (!wajibJaminan) {
                document.querySelectorAll('.jaminan-detail').forEach(function (panel) { panel.style.display = 'none'; });
                document.querySelectorAll('.detail-required').forEach(function (el) { el.required = false; });
            }
            const hariGroup = document.getElementById('hariAngsuranGroup');
            const hariSelect = hariGroup.querySelector('select[name="hari_angsuran"]');
            hariGroup.style.display = wajibJaminan ? 'none' : '';
            hariSelect.required = !wajibJaminan;
            if (wajibJaminan) {
                hariSelect.value = '';
                // Isi otomatis dari maksimal jenis, tetapi Teller masih boleh mengubahnya.
                document.getElementById('jumlahPinjaman').value = parseInt(maksimal) || '';
                document.getElementById('jumlahPinjaman').dataset.autoGadai = '1';
            } else if (document.getElementById('jumlahPinjaman').dataset.autoGadai === '1') {
                document.getElementById('jumlahPinjaman').value = '';
                document.getElementById('jumlahPinjaman').dataset.autoGadai = '0';
            }

            updateCalculation();
        });

        function aturKategoriJaminan() {
            const dipilih = document.querySelector('input[name="kategori_jaminan"]:checked');
            const kategori = dipilih ? dipilih.value : '';
            document.querySelectorAll('.jaminan-detail').forEach(function (panel) {
                const aktif = panel.getAttribute('data-kategori') === kategori;
                panel.style.display = aktif ? '' : 'none';
                panel.querySelectorAll('.detail-required').forEach(function (el) { el.required = aktif; });
            });
        }
        document.querySelectorAll('.kategori-jaminan').forEach(function (radio) {
            radio.addEventListener('change', aturKategoriJaminan);
        });

        // Update calculation when amount, tenor or frekuensi changes
        document.getElementById('jumlahPinjaman').addEventListener('input', updateCalculation);
        document.getElementById('tenorSelect').addEventListener('change', updateCalculation);
        document.querySelectorAll('input[name="frekuensi"]').forEach(function (r) {
            r.addEventListener('change', updateCalculation);
        });

        function updateCalculation() {
            const jumlah = parseInt(document.getElementById('jumlahPinjaman').value) || 0;
            const tenor = parseInt(document.getElementById('tenorSelect').value) || 0;
            const frekuensi = getFrekuensiTerpilih();
            const tarif = getTarifTerpilih();
            const calculationInfo = document.getElementById('calculationInfo');

            if (jumlah > 0 && tenor > 0 && frekuensi && tarif[tenor] !== undefined) {
                const opt = document.getElementById('jenisPinjamanSelect').selectedOptions[0];
                const gadai = opt && opt.getAttribute('data-jaminan') === '1';
                const h = gadai
                    ? hitungPinjamanGadai(jumlah, tarif[tenor], tenor, frekuensi)
                    : hitungPinjaman(jumlah, frekuensi === 'dua_mingguan' ? 45 : tarif[tenor], tenor, frekuensi, 'flat');
                if (!h) {
                    calculationInfo.style.display = 'none';
                    return;
                }
                const akhir = h.rincian[h.rincian.length - 1].jumlah;

                document.getElementById('displayBunga').textContent = gadai
                    ? tarif[tenor] + '% + Rp ' + h.biayaMingguanGadai.toLocaleString('id-ID') + '/minggu'
                    : (frekuensi === 'dua_mingguan' ? '45%' : tarif[tenor] + '%');
                document.getElementById('displayTotal').textContent = 'Rp ' + h.total.toLocaleString('id-ID');
                document.getElementById('labelAngsuran').textContent = 'Angsuran/' + labelPeriode(frekuensi);
                document.getElementById('displayAngsuran').textContent = 'Rp ' + h.angsuran.toLocaleString('id-ID');
                document.getElementById('displayCicilan').textContent = h.jumlahCicilan + '× cicilan' +
                    (akhir !== h.angsuran ? ' (terakhir Rp ' + akhir.toLocaleString('id-ID') + ')' : '');

                calculationInfo.style.display = 'block';
            } else {
                calculationInfo.style.display = 'none';
            }
        }

        // Detail Modal
        var detailModal = document.getElementById('detailModal');
        detailModal.addEventListener('show.bs.modal', function (event) {
            var button = event.relatedTarget;
            document.getElementById('detail_kode').textContent = button.getAttribute('data-kode');
            document.getElementById('detail_nama').textContent = button.getAttribute('data-nama');
            document.getElementById('detail_kode_anggota').textContent = button.getAttribute('data-kode_anggota');
            document.getElementById('detail_jenis').textContent = button.getAttribute('data-jenis');
            document.getElementById('detail_jumlah').textContent = 'Rp ' + Number(button.getAttribute('data-jumlah')).toLocaleString('id-ID');
            document.getElementById('detail_tenor').textContent = button.getAttribute('data-tenor');
            document.getElementById('detail_bunga').textContent = button.getAttribute('data-bunga');
            
            var status = button.getAttribute('data-status');
            var statusClass = {
                'menunggu': 'warning',
                'disetujui': 'success',
                'ditolak': 'danger',
                'dicairkan': 'info'
            };
            document.getElementById('detail_status').innerHTML = '<span class="badge bg-' + statusClass[status] + '">' + status.charAt(0).toUpperCase() + status.slice(1) + '</span>';
            
            document.getElementById('detail_tujuan').textContent = button.getAttribute('data-tujuan') || '-';
            document.getElementById('detail_tanggal').textContent = button.getAttribute('data-tanggal');
            document.getElementById('detail_catatan').textContent = button.getAttribute('data-catatan') || '-';
            document.getElementById('detail_admin').textContent = button.getAttribute('data-admin') || '-';
            document.getElementById('detail_tanggal_persetujuan').textContent = button.getAttribute('data-tanggal_persetujuan') || '-';
        });

        // Form validation
        document.getElementById('formPengajuan').addEventListener('submit', function(e) {
            const selectedOption = document.getElementById('jenisPinjamanSelect').options[document.getElementById('jenisPinjamanSelect').selectedIndex];
            const maksimal = selectedOption ? parseInt(selectedOption.getAttribute('data-maksimal')) : 0;
            const jumlah = parseInt(document.getElementById('jumlahPinjaman').value);
            const tenor = parseInt(document.getElementById('tenorSelect').value);

            if (jumlah > maksimal) {
                e.preventDefault();
                alert('Jumlah pinjaman melebihi batas maksimal! Maksimal: Rp ' + maksimal.toLocaleString('id-ID'));
                return false;
            }
            const gadai = selectedOption && selectedOption.getAttribute('data-jaminan') === '1';
            if (!gadai && getFrekuensiTerpilih() === 'dua_mingguan' && tenor !== 6) {
                e.preventDefault();
                alert('Angsuran 2 minggu sekali tanpa jaminan hanya tersedia untuk tenor 6 bulan.');
                return false;
            }
            if (!gadai && getFrekuensiTerpilih() === 'dua_mingguan' && [2000000, 3000000].indexOf(jumlah) === -1) {
                e.preventDefault();
                alert('Tenor 6 bulan / 2 minggu sekali hanya tersedia untuk Rp 2 juta atau Rp 3 juta.');
                return false;
            }
            if (selectedOption && selectedOption.getAttribute('data-jaminan') === '1' && biayaMingguanGadai(jumlah) === null) {
                e.preventDefault();
                alert('Nominal pinjaman gadai harus kelipatan Rp 1 juta.');
                return false;
            }

            if (getTarifTerpilih()[tenor] === undefined) {
                e.preventDefault();
                alert('Tenor tidak tersedia untuk jenis pinjaman ini.');
                return false;
            }

            if (!getFrekuensiTerpilih()) {
                e.preventDefault();
                alert('Pilih frekuensi angsuran.');
                return false;
            }
            
            return true;
        });

        // Auto-focus on first input
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('anggotaSelect').focus();
        });
    </script>
</body>
</html>
