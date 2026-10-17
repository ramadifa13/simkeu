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

// Check if user has access to this page
if (!isAdmin() && !isTeller()) {
    $_SESSION['error'] = "Anda tidak memiliki akses ke halaman ini";
    header("Location: dashboard.php");
    exit();
}

$page_title = "Pembayaran Angsuran";

// Denda otomatis (sekali sehari); denda yang diinput manual tidak ditimpa
perbaruiDendaOtomatis($db);

// Handle payment
if ($_POST) {
    // Koreksi langsung (HANYA Admin; fungsi memeriksa peran di server)
    if (isset($_POST['batalkan_bayar'])) {
        try {
            $_SESSION['success'] = batalkanPembayaranAngsuran(
                $db,
                (int) $_POST['id'],
                isset($_POST['alasan']) ? $_POST['alasan'] : '',
                $_SESSION['user_id']
            );
        } catch (Exception $e) {
            $_SESSION['error'] = "Gagal membatalkan pembayaran: " . $e->getMessage();
        }
        header("Location: angsuran.php" . (isset($_GET['pinjaman_id']) ? "?pinjaman_id=" . (int) $_GET['pinjaman_id'] : ""));
        exit();
    }

    // Teller mengajukan pembatalan (pembayaran belum dibalik sampai Admin memutuskan)
    if (isset($_POST['ajukan_pembatalan'])) {
        try {
            $_SESSION['success'] = ajukanPembatalanAngsuran(
                $db,
                (int) $_POST['id'],
                isset($_POST['alasan']) ? $_POST['alasan'] : '',
                $_SESSION['user_id']
            );
        } catch (Exception $e) {
            $_SESSION['error'] = "Gagal mengajukan pembatalan: " . $e->getMessage();
        }
        header("Location: angsuran.php" . (isset($_GET['pinjaman_id']) ? "?pinjaman_id=" . (int) $_GET['pinjaman_id'] : ""));
        exit();
    }

    // Admin menyetujui / menolak pengajuan pembatalan (Teller ditolak di server oleh fungsi)
    if (isset($_POST['putuskan_pembatalan'])) {
        try {
            $_SESSION['success'] = putuskanPembatalanAngsuran(
                $db,
                (int) $_POST['pengajuan_id'],
                isset($_POST['keputusan']) && $_POST['keputusan'] === 'setuju',
                isset($_POST['catatan']) ? $_POST['catatan'] : '',
                $_SESSION['user_id']
            );
        } catch (Exception $e) {
            $_SESSION['error'] = "Gagal memproses pengajuan pembatalan: " . $e->getMessage();
        }
        header("Location: angsuran.php" . (isset($_GET['pinjaman_id']) ? "?pinjaman_id=" . (int) $_GET['pinjaman_id'] : ""));
        exit();
    }

    if (isset($_POST['bayar'])) {
        $id = $_POST['id'];
        // Denda: kosong = otomatis (tarif per hari x hari terlambat); diisi = manual
        $denda_input = isset($_POST['denda']) ? trim($_POST['denda']) : '';
        $denda = 0;
        $denda_manual = 0;
        $user_id = $_SESSION['user_id'];
        
        try {
            $db->beginTransaction();
            
            // Get data angsuran lengkap sebelum diupdate
            $query_angsuran = "SELECT a.*, p.anggota_id, p.kode_pinjaman, p.jumlah_pinjaman, p.sisa_pokok 
                              FROM angsuran a
                              JOIN pinjaman p ON a.pinjaman_id = p.id
                              WHERE a.id = :id";
            $stmt_angsuran = $db->prepare($query_angsuran);
            $stmt_angsuran->bindParam(':id', $id);
            $stmt_angsuran->execute();
            $angsuran_data = $stmt_angsuran->fetch(PDO::FETCH_ASSOC);
            
            if (!$angsuran_data) {
                throw new Exception("Data angsuran tidak ditemukan");
            }
            if ($angsuran_data['status'] === 'lunas') {
                throw new Exception("Angsuran ini sudah dibayar");
            }

            // Hitung denda otomatis; nilai berbeda dari hitungan = manual
            $denda_auto = hitungDendaAngsuran($db, $angsuran_data, $angsuran_data['jumlah_pinjaman']);
            if ($denda_input === '') {
                $denda = $denda_auto['denda'];
            } elseif (is_numeric($denda_input) && (float) $denda_input >= 0) {
                $denda = (float) $denda_input;
            } else {
                throw new Exception("Denda harus berupa angka 0 atau lebih");
            }
            $denda_manual = abs($denda - $denda_auto['denda']) > 0.5 ? 1 : 0;
            
            // Update angsuran
            $set_manual = kolomAda($db, 'angsuran', 'denda_manual') ? ", denda_manual = " . (int) $denda_manual : '';
            $query = "UPDATE angsuran SET tanggal_bayar = CURDATE(), status = 'lunas', denda = :denda" . $set_manual . " WHERE id = :id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':id', $id);
            $stmt->bindParam(':denda', $denda);
            
            if (!$stmt->execute()) {
                throw new Exception("Gagal mengupdate status angsuran");
            }
            
            // Update sisa pokok di pinjaman
            $sisa_pokok_baru = $angsuran_data['sisa_pokok'] - $angsuran_data['pokok'];
            $status_pinjaman = ($sisa_pokok_baru <= 0) ? 'lunas' : 'aktif';
            
            $query_update_pinjaman = "UPDATE pinjaman SET 
                                      sisa_pokok = :sisa_pokok,
                                      status = :status 
                                      WHERE id = :pinjaman_id";
            $stmt_update = $db->prepare($query_update_pinjaman);
            $stmt_update->bindParam(':sisa_pokok', $sisa_pokok_baru);
            $stmt_update->bindParam(':status', $status_pinjaman);
            $stmt_update->bindParam(':pinjaman_id', $angsuran_data['pinjaman_id']);
            $stmt_update->execute();

            // Semua cicilan lunas -> pinjaman lunas + catat tanggal lunas
            tandaiLunasBilaSelesai($db, $angsuran_data['pinjaman_id']);
            
            // Hitung total pembayaran
            $total_bayar = $angsuran_data['jumlah_angsuran'] + $denda;
            
            // ============================================
            // CEK APAKAH JENIS SIMPANAN 'ANG' SUDAH ADA
            // ============================================
            $query_cek_jenis = "SELECT id FROM jenis_simpanan WHERE kode_jenis = 'ANG'";
            $stmt_cek = $db->prepare($query_cek_jenis);
            $stmt_cek->execute();
            $jenis_angsuran = $stmt_cek->fetch(PDO::FETCH_ASSOC);
            
            // Jika belum ada, buat dulu
            if (!$jenis_angsuran) {
                $query_insert_jenis = "INSERT INTO jenis_simpanan (kode_jenis, nama_jenis, deskripsi, status) 
                                      VALUES ('ANG', 'Pembayaran Angsuran', 'Pembayaran angsuran pinjaman', 'aktif')";
                $stmt_insert = $db->prepare($query_insert_jenis);
                $stmt_insert->execute();
                
                // Ambil ID yang baru dibuat
                $jenis_angsuran_id = $db->lastInsertId();
            } else {
                $jenis_angsuran_id = $jenis_angsuran['id'];
            }
            
            // ============================================
            // TAMBAHKAN KE TABEL TRANSAKSI (PAKAI ID YANG BENAR)
            // ============================================
            // Generate kode transaksi
            $kode_transaksi = 'ANG-' . date('Ymd') . '-' . str_pad(mt_rand(1, 999), 3, '0', STR_PAD_LEFT);
            
            // Insert ke tabel transaksi - GUNAKAN JENIS_SIMPANAN_ID YANG BENAR
            $query_transaksi = "INSERT INTO transaksi 
                               (kode_transaksi, anggota_id, jenis_simpanan_id, jenis_transaksi, 
                                jumlah, saldo_sebelum, saldo_sesudah, keterangan, user_id, tanggal_transaksi) 
                               VALUES 
                               (:kode_transaksi, :anggota_id, :jenis_angsuran_id, 'setoran', 
                                :jumlah, 0, :jumlah, :keterangan, :user_id, NOW())";
            $stmt_transaksi = $db->prepare($query_transaksi);
            $keterangan_transaksi = "Pembayaran angsuran ke-" . $angsuran_data['angsuran_ke'] . " - " . $angsuran_data['kode_pinjaman'];
            $stmt_transaksi->bindParam(':kode_transaksi', $kode_transaksi);
            $stmt_transaksi->bindParam(':anggota_id', $angsuran_data['anggota_id']);
            $stmt_transaksi->bindParam(':jenis_angsuran_id', $jenis_angsuran_id);
            $stmt_transaksi->bindParam(':jumlah', $total_bayar);
            $stmt_transaksi->bindParam(':keterangan', $keterangan_transaksi);
            $stmt_transaksi->bindParam(':user_id', $user_id);
            $stmt_transaksi->execute();
            
            // Dapatkan ID transaksi yang baru diinsert
            $transaksi_id = $db->lastInsertId();
            
            // ============================================
            // TAMBAHKAN KE TABEL KAS (WAJIB!)
            // ============================================
            // Get saldo kas terakhir
            $query_saldo_kas = "SELECT saldo_sesudah FROM kas ORDER BY id DESC LIMIT 1";
            $stmt_saldo_kas = $db->prepare($query_saldo_kas);
            $stmt_saldo_kas->execute();
            $saldo_kas = $stmt_saldo_kas->fetch(PDO::FETCH_ASSOC);
            $saldo_sebelum = $saldo_kas ? $saldo_kas['saldo_sesudah'] : 0;
            $saldo_sesudah = $saldo_sebelum + $total_bayar;
            
            // Insert ke tabel kas
            $keterangan_kas = "Pembayaran angsuran ke-" . $angsuran_data['angsuran_ke'] . " - " . $angsuran_data['kode_pinjaman'];
            
            $query_kas = "INSERT INTO kas (transaksi_id, jenis, jumlah, saldo_sebelum, saldo_sesudah, keterangan, tanggal) 
                         VALUES (:transaksi_id, 'masuk', :jumlah, :saldo_sebelum, :saldo_sesudah, :keterangan, NOW())";
            $stmt_kas = $db->prepare($query_kas);
            $stmt_kas->bindParam(':transaksi_id', $transaksi_id);
            $stmt_kas->bindParam(':jumlah', $total_bayar);
            $stmt_kas->bindParam(':saldo_sebelum', $saldo_sebelum);
            $stmt_kas->bindParam(':saldo_sesudah', $saldo_sesudah);
            $stmt_kas->bindParam(':keterangan', $keterangan_kas);
            $stmt_kas->execute();
            
            // ============================================
            // TAMBAHKAN KE TABEL SIMPANAN (UNTUK JENIS 'ANG')
            // ============================================
            // Cek apakah sudah ada record simpanan untuk jenis 'ANG'
            $query_cek_simpanan = "SELECT id, saldo FROM simpanan 
                                  WHERE anggota_id = :anggota_id AND jenis_simpanan_id = :jenis_angsuran_id";
            $stmt_cek_simpanan = $db->prepare($query_cek_simpanan);
            $stmt_cek_simpanan->bindParam(':anggota_id', $angsuran_data['anggota_id']);
            $stmt_cek_simpanan->bindParam(':jenis_angsuran_id', $jenis_angsuran_id);
            $stmt_cek_simpanan->execute();
            $simpanan = $stmt_cek_simpanan->fetch(PDO::FETCH_ASSOC);
            
            if ($simpanan) {
                // Update saldo yang sudah ada
                $saldo_baru = $simpanan['saldo'] + $total_bayar;
                $query_update_simpanan = "UPDATE simpanan SET saldo = :saldo WHERE id = :id";
                $stmt_update_simpanan = $db->prepare($query_update_simpanan);
                $stmt_update_simpanan->bindParam(':saldo', $saldo_baru);
                $stmt_update_simpanan->bindParam(':id', $simpanan['id']);
                $stmt_update_simpanan->execute();
            } else {
                // Insert record baru
                $query_insert_simpanan = "INSERT INTO simpanan (anggota_id, jenis_simpanan_id, saldo) 
                                         VALUES (:anggota_id, :jenis_angsuran_id, :saldo)";
                $stmt_insert_simpanan = $db->prepare($query_insert_simpanan);
                $stmt_insert_simpanan->bindParam(':anggota_id', $angsuran_data['anggota_id']);
                $stmt_insert_simpanan->bindParam(':jenis_angsuran_id', $jenis_angsuran_id);
                $stmt_insert_simpanan->bindParam(':saldo', $total_bayar);
                $stmt_insert_simpanan->execute();
            }
            
            $db->commit();
            $_SESSION['success'] = "Pembayaran angsuran ke-" . $angsuran_data['angsuran_ke'] . " berhasil dicatat. Kas bertambah " . formatRupiah($total_bayar);
            
        } catch (Exception $e) {
            $db->rollBack();
            $_SESSION['error'] = "Gagal mencatat pembayaran: " . $e->getMessage();
        }
        
        // Redirect untuk menghindari resubmission
        header("Location: angsuran.php" . (isset($_GET['pinjaman_id']) ? "?pinjaman_id=" . $_GET['pinjaman_id'] : ""));
        exit();
    }
}

// Debug: Cek data di database
$query_debug_pinjaman = "SELECT p.*, a.nama_lengkap, a.kode_anggota, jp.nama_jenis
                         FROM pinjaman p
                         JOIN anggota a ON p.anggota_id = a.id
                         JOIN jenis_pinjaman jp ON p.jenis_pinjaman_id = jp.id
                         ORDER BY p.tanggal_pencairan DESC";
$stmt_debug = $db->prepare($query_debug_pinjaman);
$stmt_debug->execute();
$all_pinjaman = $stmt_debug->fetchAll(PDO::FETCH_ASSOC);

// Get active loans - Tampilkan semua pinjaman yang punya angsuran
$kolom_hari = kolomAda($db, 'pengajuan_pinjaman', 'hari_angsuran')
    ? "(SELECT pp.hari_angsuran FROM pengajuan_pinjaman pp WHERE pp.id = p.pengajuan_id) AS hari_angsuran,"
    : "NULL AS hari_angsuran,";
$query_pinjaman = "SELECT p.*, a.nama_lengkap, a.kode_anggota, jp.nama_jenis, $kolom_hari
                          (SELECT COUNT(*) FROM angsuran WHERE pinjaman_id = p.id) as total_angsuran,
                          (SELECT COUNT(*) FROM angsuran WHERE pinjaman_id = p.id AND status = 'lunas') as angsuran_lunas
                   FROM pinjaman p
                   JOIN anggota a ON p.anggota_id = a.id
                   JOIN jenis_pinjaman jp ON p.jenis_pinjaman_id = jp.id
                   WHERE p.status IN ('aktif', 'lunas') 
                   AND EXISTS (SELECT 1 FROM angsuran WHERE pinjaman_id = p.id)
                   ORDER BY p.tanggal_pencairan DESC";

$stmt_pinjaman = $db->prepare($query_pinjaman);
$stmt_pinjaman->execute();
$pinjaman_list = $stmt_pinjaman->fetchAll(PDO::FETCH_ASSOC);

// Penanda pengajuan pembatalan per pinjaman: menunggu (kuning), disetujui (hijau), ditolak (merah)
$jumlah_filter = array('menunggu' => 0, 'disetujui' => 0, 'ditolak' => 0);
foreach ($pinjaman_list as $i => $pj) {
    $pinjaman_list[$i]['n_pb'] = array('menunggu' => 0, 'disetujui' => 0, 'ditolak' => 0);
}
if (tabelPengajuanPembatalanAda($db) && !empty($pinjaman_list)) {
    $rows = $db->query("SELECT x.pinjaman_id, pb.status, x.status AS status_angsuran
                        FROM pengajuan_pembatalan_angsuran pb JOIN angsuran x ON pb.angsuran_id = x.id
                        WHERE pb.id = (SELECT MAX(id) FROM pengajuan_pembatalan_angsuran WHERE angsuran_id = pb.angsuran_id)")
                 ->fetchAll(PDO::FETCH_ASSOC);
    $per_pinjaman = array();
    foreach ($rows as $r) {
        $st = $r['status'];
        if (($st === 'disetujui' && $r['status_angsuran'] === 'lunas') || ($st === 'ditolak' && $r['status_angsuran'] !== 'lunas')) {
            continue; // keputusan lama yang sudah tidak relevan (cicilan dibayar lagi)
        }
        $per_pinjaman[$r['pinjaman_id']][$st] = (isset($per_pinjaman[$r['pinjaman_id']][$st]) ? $per_pinjaman[$r['pinjaman_id']][$st] : 0) + 1;
    }
    foreach ($pinjaman_list as $i => $pj) {
        if (isset($per_pinjaman[$pj['id']])) {
            foreach ($per_pinjaman[$pj['id']] as $st => $n) {
                $pinjaman_list[$i]['n_pb'][$st] = $n;
                $jumlah_filter[$st]++;
            }
        }
    }
}
// Filter daftar pinjaman: Admin hanya "menunggu"; Teller menunggu / disetujui / ditolak
$filter_pb = isset($_GET['filter']) ? $_GET['filter'] : '';
if (!in_array($filter_pb, isAdmin() ? array('menunggu') : array('menunggu', 'disetujui', 'ditolak'), true)) {
    $filter_pb = '';
}
if ($filter_pb !== '') {
    $pinjaman_list = array_values(array_filter($pinjaman_list, function ($pj) use ($filter_pb) {
        return $pj['n_pb'][$filter_pb] > 0;
    }));
}
// Filter hari angsuran (Senin - Minggu), digabung dengan filter pengajuan pembatalan
$jumlah_hari = array_fill(1, 7, 0);
foreach ($pinjaman_list as $pj) {
    if (!empty($pj['hari_angsuran'])) {
        $jumlah_hari[(int) $pj['hari_angsuran']]++;
    }
}
$filter_hari = isset($_GET['hari']) ? (int) $_GET['hari'] : 0;
if ($filter_hari < 1 || $filter_hari > 7) {
    $filter_hari = 0;
} else {
    $pinjaman_list = array_values(array_filter($pinjaman_list, function ($pj) use ($filter_hari) {
        return (int) $pj['hari_angsuran'] === $filter_hari;
    }));
}
$qs_filter = ($filter_pb !== '' ? '&filter=' . $filter_pb : '') . ($filter_hari ? '&hari=' . $filter_hari : '');
$qs_hari = $filter_hari ? '&hari=' . $filter_hari : '';
$qs_pb = $filter_pb !== '' ? '&filter=' . $filter_pb : '';

// Get installments for selected loan
$angsuran_list = [];
$pembatalan = [];
$jumlah_menunggu = jumlahPengajuanPembatalan($db);
if (isset($_GET['pinjaman_id'])) {
    $query_angsuran = "SELECT a.*, p.kode_pinjaman, p.jumlah_pinjaman
                       FROM angsuran a
                       JOIN pinjaman p ON a.pinjaman_id = p.id
                       WHERE a.pinjaman_id = :pinjaman_id
                       ORDER BY a.angsuran_ke";
    $stmt_angsuran = $db->prepare($query_angsuran);
    $stmt_angsuran->bindParam(':pinjaman_id', $_GET['pinjaman_id']);
    $stmt_angsuran->execute();
    $angsuran_list = $stmt_angsuran->fetchAll(PDO::FETCH_ASSOC);
    $pembatalan = pengajuanPembatalanTerakhir($db, array_column($angsuran_list, 'id'));
}

// Get statistics
$query_stats = "SELECT 
    COUNT(*) as total_pinjaman,
    COUNT(CASE WHEN status = 'aktif' THEN 1 END) as aktif,
    COUNT(CASE WHEN status = 'lunas' THEN 1 END) as lunas,
    COUNT(CASE WHEN status = 'macet' THEN 1 END) as macet
    FROM pinjaman";
$stmt_stats = $db->prepare($query_stats);
$stmt_stats->execute();
$stats = $stmt_stats->fetch(PDO::FETCH_ASSOC);

// Fungsi format rupiah
function formatRupiah($angka) {
    return 'Rp ' . number_format($angka, 0, ',', '.');
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pembayaran Angsuran - <?php echo APP_NAME; ?></title>
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
        .loan-list {
            max-height: 500px;
            overflow-y: auto;
        }
        .debug-info {
            background-color: #f8f9fa;
            border-left: 4px solid #dc3545;
            padding: 10px;
            margin-bottom: 15px;
            font-size: 0.875rem;
        }
        .progress {
            height: 6px;
            margin-top: 5px;
        }
        .info-card {
            background-color: #e8f4fd;
            border-left: 4px solid #3498db;
            padding: 10px 15px;
            margin-bottom: 15px;
            border-radius: 5px;
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
                        <i class="fas fa-calendar-check me-2"></i>Pembayaran Angsuran
                    </h1>
                </div>

                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="fas fa-check-circle me-2"></i>
                        <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['error'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Info Penting -->
                <div class="info-card">
                    <i class="fas fa-info-circle me-2 text-primary"></i>
                    <strong>Informasi:</strong> Pembayaran angsuran akan otomatis menambah saldo kas dan tercatat di laporan transaksi.
                </div>

                <?php if ($jumlah_menunggu > 0): ?>
                <div class="alert alert-warning py-2">
                    <i class="fas fa-hourglass-half me-2"></i>
                    <strong><?php echo $jumlah_menunggu; ?> pengajuan pembatalan</strong>
                    <?php echo isAdmin() ? 'menunggu keputusan Anda (Approve / Tolak pada kolom Aksi angsuran berstatus "Pengajuan Pembatalan").' : 'menunggu keputusan Admin.'; ?>
                </div>
                <?php endif; ?>

                <!-- Statistics Cards -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card stat-card bg-primary text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h4><?php echo $stats['total_pinjaman']; ?></h4>
                                        <p>Total Pinjaman</p>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-hand-holding-usd fa-2x"></i>
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
                                        <h4><?php echo $stats['aktif']; ?></h4>
                                        <p>Pinjaman Aktif</p>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-play-circle fa-2x"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stat-card bg-info text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h4><?php echo $stats['lunas']; ?></h4>
                                        <p>Lunas</p>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-flag-checkered fa-2x"></i>
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
                                        <h4><?php echo $stats['macet']; ?></h4>
                                        <p>Macet</p>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-exclamation-triangle fa-2x"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <!-- Loan List -->
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-list me-2"></i>Daftar Pinjaman
                                </h5>
                                <small class="text-muted"><?php echo count($pinjaman_list); ?> pinjaman</small>
                            </div>
                            <div class="card-body">
                                <div class="mb-3 d-flex flex-wrap gap-1">
                                    <?php
                                    $chips = isAdmin()
                                        ? array('menunggu' => array('Pengajuan Pembatalan', 'warning'))
                                        : array('menunggu' => array('Pengajuan', 'warning'), 'disetujui' => array('Approve', 'success'), 'ditolak' => array('Tolak', 'danger'));
                                    ?>
                                    <a href="angsuran.php<?php echo $filter_hari ? '?hari=' . $filter_hari : ''; ?>" class="btn btn-sm <?php echo $filter_pb === '' ? 'btn-secondary' : 'btn-outline-secondary'; ?>">Semua</a>
                                    <?php foreach ($chips as $kunci => $c): ?>
                                    <a href="?filter=<?php echo $kunci . $qs_hari; ?>" class="btn btn-sm <?php echo $filter_pb === $kunci ? 'btn-' . $c[1] : 'btn-outline-' . $c[1]; ?>">
                                        <?php echo $c[0]; ?> <span class="badge bg-<?php echo $c[1]; ?> <?php echo $c[1] === 'warning' ? 'text-dark' : ''; ?>"><?php echo $jumlah_filter[$kunci]; ?></span>
                                    </a>
                                    <?php endforeach; ?>
                                </div>
                                <div class="mb-3 d-flex flex-wrap gap-1 align-items-center">
                                    <small class="text-muted me-1">Hari angsuran:</small>
                                    <a href="angsuran.php<?php echo $qs_pb ? '?' . ltrim($qs_pb, '&') : ''; ?>" class="btn btn-sm <?php echo !$filter_hari ? 'btn-primary' : 'btn-outline-primary'; ?>">Semua</a>
                                    <?php foreach (daftarHariAngsuran() as $no => $nama_hari): ?>
                                    <a href="?hari=<?php echo $no . $qs_pb; ?>" class="btn btn-sm <?php echo $filter_hari === $no ? 'btn-primary' : 'btn-outline-primary'; ?>">
                                        <?php echo $nama_hari; ?> <span class="badge bg-secondary"><?php echo $jumlah_hari[$no]; ?></span>
                                    </a>
                                    <?php endforeach; ?>
                                </div>
                                <div class="loan-list">
                                    <?php if (empty($pinjaman_list)): ?>
                                    <div class="text-center py-4">
                                        <i class="fas fa-info-circle fa-3x text-info mb-3"></i>
                                        <h5 class="text-muted">Tidak ada pinjaman</h5>
                                        <p class="text-muted">Tidak ada pinjaman yang memiliki angsuran.</p>
                                    </div>
                                    <?php else: ?>
                                    <div class="list-group">
                                        <?php foreach ($pinjaman_list as $pinjaman): 
                                            $progress = $pinjaman['total_angsuran'] > 0 ? ($pinjaman['angsuran_lunas'] / $pinjaman['total_angsuran'] * 100) : 0;
                                        ?>
                                        <a href="?pinjaman_id=<?php echo $pinjaman['id'] . $qs_filter; ?>" 
                                           class="list-group-item list-group-item-action <?php echo isset($_GET['pinjaman_id']) && $_GET['pinjaman_id'] == $pinjaman['id'] ? 'active' : ''; ?>">
                                            <div class="d-flex w-100 justify-content-between">
                                                <h6 class="mb-1"><?php echo $pinjaman['kode_pinjaman']; ?></h6>
                                                <div>
                                                    <?php if ($pinjaman['n_pb']['menunggu'] > 0): ?>
                                                    <span class="badge bg-warning text-dark" title="Pengajuan pembatalan menunggu keputusan"><i class="fas fa-hourglass-half"></i> <?php echo $pinjaman['n_pb']['menunggu']; ?></span>
                                                    <?php endif; ?>
                                                    <?php if (isTeller() && $pinjaman['n_pb']['disetujui'] > 0): ?>
                                                    <span class="badge bg-success" title="Pembatalan disetujui Admin"><i class="fas fa-check"></i> <?php echo $pinjaman['n_pb']['disetujui']; ?></span>
                                                    <?php endif; ?>
                                                    <?php if (isTeller() && $pinjaman['n_pb']['ditolak'] > 0): ?>
                                                    <span class="badge bg-danger" title="Pembatalan ditolak Admin"><i class="fas fa-times"></i> <?php echo $pinjaman['n_pb']['ditolak']; ?></span>
                                                    <?php endif; ?>
                                                    <span class="badge bg-<?php echo $pinjaman['status'] == 'aktif' ? 'success' : ($pinjaman['status'] == 'lunas' ? 'info' : 'danger'); ?>">
                                                        <?php echo $pinjaman['status']; ?>
                                                    </span>
                                                    <small class="<?php echo isset($_GET['pinjaman_id']) && $_GET['pinjaman_id'] == $pinjaman['id'] ? 'text-white' : 'text-muted'; ?>">
                                                        <?php echo !empty($pinjaman['hari_angsuran']) ? labelHariAngsuran($pinjaman['hari_angsuran']) . ' · ' : ''; ?><?php echo labelTenorRow($pinjaman); ?>
                                                    </small>
                                                </div>
                                            </div>
                                            <p class="mb-1"><?php echo $pinjaman['nama_lengkap']; ?></p>
                                            <div class="d-flex justify-content-between align-items-center">
                                                <small class="<?php echo isset($_GET['pinjaman_id']) && $_GET['pinjaman_id'] == $pinjaman['id'] ? 'text-light' : 'text-muted'; ?>">
                                                    <?php echo formatRupiah($pinjaman['jumlah_pinjaman']); ?> | 
                                                    Sisa: <?php echo formatRupiah($pinjaman['sisa_pokok']); ?>
                                                </small>
                                                <small class="<?php echo isset($_GET['pinjaman_id']) && $_GET['pinjaman_id'] == $pinjaman['id'] ? 'text-light' : 'text-muted'; ?>">
                                                    <?php echo $pinjaman['angsuran_lunas']; ?>/<?php echo $pinjaman['total_angsuran']; ?>
                                                </small>
                                            </div>
                                            <div class="progress">
                                                <div class="progress-bar bg-success" style="width: <?php echo $progress; ?>%"></div>
                                            </div>
                                        </a>
                                        <?php if ($pinjaman['total_angsuran'] > 0 && $pinjaman['angsuran_lunas'] == $pinjaman['total_angsuran']): ?>
                                        <div class="list-group-item py-1 text-end bg-light">
                                            <a class="btn btn-sm btn-success" target="_blank" rel="noopener"
                                               href="cetak_bukti_pelunasan.php?id=<?php echo (int) $pinjaman['id']; ?>">
                                                <i class="fas fa-print me-1"></i>Cetak Bukti Pelunasan
                                            </a>
                                        </div>
                                        <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Installments List -->
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-calendar-alt me-2"></i>Daftar Angsuran
                                </h5>
                                <?php if (isset($_GET['pinjaman_id'])): ?>
                                <div>
                                    <span class="badge bg-primary">
                                        <?php echo count($angsuran_list); ?> angsuran
                                    </span>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div class="card-body">
                                <?php if (!isset($_GET['pinjaman_id'])): ?>
                                <div class="text-center py-5">
                                    <i class="fas fa-hand-pointer fa-3x text-primary mb-3"></i>
                                    <h5 class="text-muted">Pilih pinjaman</h5>
                                    <p class="text-muted">Pilih pinjaman dari daftar di sebelah kiri untuk melihat angsurannya.</p>
                                </div>
                                <?php elseif (empty($angsuran_list)): ?>
                                <div class="text-center py-5">
                                    <i class="fas fa-info-circle fa-3x text-info mb-3"></i>
                                    <h5 class="text-muted">Belum ada angsuran</h5>
                                    <p class="text-muted">Belum ada jadwal angsuran untuk pinjaman ini.</p>
                                </div>
                                <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-sm table-striped">
                                        <thead>
                                            <tr>
                                                <th>Ke</th>
                                                <th>Jatuh Tempo</th>
                                                <th>Pokok</th>
                                                <th>Bunga</th>
                                                <th>Denda</th>
                                                <th>Total</th>
                                                <th>Status</th>
                                                <th>Aksi</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($angsuran_list as $angsuran): ?>
                                            <tr class="<?php echo $angsuran['tanggal_bayar'] ? 'table-success' : ($angsuran['tanggal_jatuh_tempo'] < date('Y-m-d') ? 'table-danger' : ''); ?>">
                                                <td><?php echo $angsuran['angsuran_ke']; ?><small class="text-muted">/<?php echo count($angsuran_list); ?></small></td>
                                                <td>
                                                    <small><?php echo date('d/m/Y', strtotime($angsuran['tanggal_jatuh_tempo'])); ?></small>
                                                    <?php if (!$angsuran['tanggal_bayar'] && $angsuran['tanggal_jatuh_tempo'] < date('Y-m-d')): ?>
                                                    <br><small class="text-danger">Terlambat</small>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo formatRupiah($angsuran['pokok']); ?></td>
                                                <td><?php echo formatRupiah($angsuran['bunga']); ?></td>
                                                <td class="text-danger"><?php echo formatRupiah($angsuran['denda']); ?></td>
                                                <td class="fw-bold text-primary"><?php echo formatRupiah($angsuran['jumlah_angsuran']); ?></td>
                                                <td>
                                                    <?php
                                                    $pb = isset($pembatalan[$angsuran['id']]) ? $pembatalan[$angsuran['id']] : null;
                                                    $menunggu = $pb && $pb['status'] === 'menunggu';
                                                    ?>
                                                    <?php if ($angsuran['status'] == 'lunas' && $menunggu): ?>
                                                        <span class="badge bg-warning text-dark">Pengajuan Pembatalan</span>
                                                        <br><small>Lunas <?php echo date('d/m', strtotime($angsuran['tanggal_bayar'])); ?></small>
                                                    <?php elseif ($angsuran['status'] == 'lunas'): ?>
                                                        <span class="badge bg-success">Lunas</span>
                                                        <br><small><?php echo date('d/m', strtotime($angsuran['tanggal_bayar'])); ?></small>
                                                    <?php elseif ($angsuran['status'] == 'telat'): ?>
                                                        <span class="badge bg-warning">Telat</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-danger">Belum Bayar</span>
                                                    <?php endif; ?>
                                                    <?php if ($pb && $pb['status'] === 'ditolak' && $angsuran['status'] == 'lunas'): ?>
                                                        <br><span class="badge bg-danger" title="<?php echo htmlspecialchars($pb['catatan_admin']); ?>">Pembatalan Ditolak Admin</span>
                                                        <?php if (!empty($pb['catatan_admin'])): ?><br><small class="text-muted"><?php echo htmlspecialchars($pb['catatan_admin']); ?></small><?php endif; ?>
                                                    <?php elseif ($pb && $pb['status'] === 'disetujui' && $angsuran['status'] != 'lunas'): ?>
                                                        <br><span class="badge bg-success" title="<?php echo htmlspecialchars($pb['catatan_admin']); ?>">Pembatalan Disetujui (Approve Admin)</span>
                                                        <?php if (!empty($pb['catatan_admin'])): ?><br><small class="text-muted"><?php echo htmlspecialchars($pb['catatan_admin']); ?></small><?php endif; ?>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="table-actions">
                                                    <?php if ($angsuran['status'] != 'lunas'):
                                                        $d_auto = hitungDendaAngsuran($db, $angsuran, $angsuran['jumlah_pinjaman']);
                                                        $d_nilai = !empty($angsuran['denda_manual']) ? $angsuran['denda'] : $d_auto['denda'];
                                                        $d_info = $d_auto['hari_dihitung'] > 0
                                                            ? 'Otomatis: Rp ' . number_format($d_auto['per_hari'], 0, ',', '.') . '/hari x ' . $d_auto['hari_dihitung'] . ' hari terlambat = Rp ' . number_format($d_auto['denda'], 0, ',', '.')
                                                            : 'Belum terlambat, denda otomatis Rp 0';
                                                    ?>
                                                        <button class="btn btn-sm btn-success" data-bs-toggle="modal" 
                                                                data-bs-target="#bayarModal" 
                                                                data-id="<?php echo $angsuran['id']; ?>"
                                                                data-angsuran_ke="<?php echo $angsuran['angsuran_ke']; ?>"
                                                                data-total="<?php echo $angsuran['jumlah_angsuran']; ?>"
                                                                data-denda="<?php echo $d_nilai; ?>"
                                                                data-denda_info="<?php echo htmlspecialchars($d_info); ?>"
                                                                title="Bayar">
                                                            <i class="fas fa-money-bill"></i> Bayar
                                                        </button>
                                                    <?php else: ?>
                                                        <span class="text-muted">
                                                            <i class="fas fa-check-circle text-success"></i> Lunas
                                                        </span>
                                                        <a class="btn btn-sm btn-outline-secondary ms-1" target="_blank" rel="noopener"
                                                           href="cetak_bukti_angsuran.php?id=<?php echo (int) $angsuran['id']; ?>"
                                                           title="Cetak Bukti Pembayaran">
                                                            <i class="fas fa-print"></i>
                                                        </a>
                                                        <?php
                                                        $data_baris = 'data-angsuran_ke="' . (int) $angsuran['angsuran_ke'] . '"'
                                                            . ' data-kode="' . htmlspecialchars($angsuran['kode_pinjaman']) . '"'
                                                            . ' data-total="' . ($angsuran['jumlah_angsuran'] + $angsuran['denda']) . '"';
                                                        ?>
                                                        <?php if (isAdmin() && $menunggu): ?>
                                                        <button type="button" class="btn btn-sm btn-success ms-1" data-bs-toggle="modal" data-bs-target="#putusModal"
                                                                data-keputusan="setuju" data-pengajuan="<?php echo (int) $pb['id']; ?>" <?php echo $data_baris; ?>
                                                                data-teller="<?php echo htmlspecialchars($pb['teller_nama']); ?>"
                                                                data-tanggal="<?php echo date('d/m/Y H:i', strtotime($pb['tanggal_pengajuan'])); ?>"
                                                                data-alasan="<?php echo htmlspecialchars($pb['alasan']); ?>" title="Approve pembatalan">
                                                            <i class="fas fa-check"></i> Approve
                                                        </button>
                                                        <button type="button" class="btn btn-sm btn-danger ms-1" data-bs-toggle="modal" data-bs-target="#putusModal"
                                                                data-keputusan="tolak" data-pengajuan="<?php echo (int) $pb['id']; ?>" <?php echo $data_baris; ?>
                                                                data-teller="<?php echo htmlspecialchars($pb['teller_nama']); ?>"
                                                                data-tanggal="<?php echo date('d/m/Y H:i', strtotime($pb['tanggal_pengajuan'])); ?>"
                                                                data-alasan="<?php echo htmlspecialchars($pb['alasan']); ?>" title="Tolak pengajuan">
                                                            <i class="fas fa-times"></i> Tolak
                                                        </button>
                                                        <?php elseif (isTeller() && !$menunggu && bisaDiajukanPembatalan($angsuran['tanggal_bayar'])): ?>
                                                        <button type="button" class="btn btn-sm btn-outline-warning ms-1" data-bs-toggle="modal" data-bs-target="#ajukanModal"
                                                                data-id="<?php echo (int) $angsuran['id']; ?>" <?php echo $data_baris; ?>
                                                                title="Ajukan pembatalan pembayaran ke Admin">
                                                            <i class="fas fa-undo"></i> Ajukan Pembatalan
                                                        </button>
                                                        <?php endif; ?>
                                                        <?php if (isAdmin() && !$menunggu): ?>
                                                        <button type="button" class="btn btn-sm btn-outline-danger ms-1" data-bs-toggle="modal"
                                                                data-bs-target="#batalModal"
                                                                data-id="<?php echo (int) $angsuran['id']; ?>"
                                                                data-angsuran_ke="<?php echo (int) $angsuran['angsuran_ke']; ?>"
                                                                data-kode="<?php echo htmlspecialchars($angsuran['kode_pinjaman']); ?>"
                                                                data-total="<?php echo $angsuran['jumlah_angsuran'] + $angsuran['denda']; ?>"
                                                                title="Batalkan pembayaran (koreksi)">
                                                            <i class="fas fa-undo"></i>
                                                        </button>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
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

    <!-- Bayar Modal -->
    <div class="modal fade" id="bayarModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title text-success">Pembayaran Angsuran</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="id" id="bayar_id">
                        <p>Angsuran ke-<span id="bayar_angsuran_ke"></span></p>
                        
                        <div class="mb-3">
                            <label class="form-label">Total yang harus dibayar:</label>
                            <input type="text" class="form-control" id="bayar_total" readonly>
                        </div>
                        
                        <div class="mb-3">
                            <label for="bayar_denda" class="form-label">Denda (otomatis, bisa diubah manual)</label>
                            <input type="number" class="form-control" id="bayar_denda" name="denda" value="0" min="0" step="1">
                            <div class="form-text" id="bayar_denda_info"></div>
                            <div class="form-text">Ubah angkanya untuk denda manual; kosongkan untuk memakai hitungan otomatis. Denda ditambahkan ke total pembayaran dan masuk ke kas.</div>
                        </div>
                        
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-1"></i>
                            <small>
                                <strong>Proses:</strong> Pembayaran ini akan:
                                <ul class="mb-0 mt-1">
                                    <li>Mencatat angsuran sebagai lunas</li>
                                    <li>Mengurangi sisa pokok pinjaman</li>
                                    <li><strong class="text-success">Menambah saldo kas</strong></li>
                                    <li><strong class="text-primary">Mencatat di laporan transaksi</strong></li>
                                </ul>
                            </small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" name="bayar" class="btn btn-success">Bayar Sekarang</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php if (isAdmin()): ?>
    <!-- Batalkan Pembayaran langsung (koreksi, hanya Admin) -->
    <div class="modal fade" id="batalModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title text-danger">Batalkan Pembayaran</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="id" id="batal_id">
                        <p>Apakah Anda yakin ingin membatalkan pembayaran:</p>
                        <p class="fw-bold mb-1">Angsuran ke-<span id="batal_ke"></span> &mdash; <span id="batal_kode"></span></p>
                        <p class="mb-3">Nominal tercatat: <strong id="batal_total"></strong></p>
                        <div class="mb-3">
                            <label for="batal_alasan" class="form-label">Alasan Pembatalan <span class="text-danger">*</span></label>
                            <textarea name="alasan" id="batal_alasan" class="form-control" rows="3" required minlength="5" maxlength="250"
                                      placeholder="Contoh: salah pencet bayar oleh teller"></textarea>
                        </div>
                        <div class="alert alert-info mb-0">
                            <small><i class="fas fa-info-circle me-1"></i>
                            Cicilan kembali <strong>belum bayar</strong>, kas dan saldo dikurangi sebesar nominal tercatat,
                            dan koreksi ini dicatat (siapa &amp; alasannya). Cicilan bisa dibayar ulang setelahnya.</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" name="batalkan_bayar" class="btn btn-danger">Batalkan Pembayaran</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Approve / Tolak pengajuan pembatalan dari Teller -->
    <div class="modal fade" id="putusModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title" id="putus_judul"></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="pengajuan_id" id="putus_id">
                        <input type="hidden" name="keputusan" id="putus_keputusan">
                        <p class="fw-bold mb-1">Angsuran ke-<span id="putus_ke"></span> &mdash; <span id="putus_kode"></span></p>
                        <p class="mb-2">Nominal tercatat: <strong id="putus_total"></strong></p>
                        <div class="border rounded p-2 mb-3 bg-light">
                            <div class="small text-muted">Diajukan oleh <strong id="putus_teller"></strong> pada <span id="putus_tanggal"></span></div>
                            <div class="mt-1"><span class="text-muted">Alasan Teller:</span><br><strong id="putus_alasan"></strong></div>
                        </div>
                        <div class="mb-3">
                            <label for="putus_catatan" class="form-label">Catatan Admin <span id="putus_catatan_opsi" class="text-muted">(opsional)</span></label>
                            <textarea name="catatan" id="putus_catatan" class="form-control" rows="2" maxlength="250"
                                      placeholder="Catatan untuk Teller..."></textarea>
                        </div>
                        <div class="alert mb-0" id="putus_info"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" name="putuskan_pembatalan" class="btn" id="putus_submit"></button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (isTeller()): ?>
    <!-- Ajukan pembatalan pembayaran (diputuskan Admin) -->
    <div class="modal fade" id="ajukanModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title text-warning">Ajukan Pembatalan</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="id" id="ajukan_id">
                        <p>Ajukan pembatalan pembayaran:</p>
                        <p class="fw-bold mb-1">Angsuran ke-<span id="ajukan_ke"></span> &mdash; <span id="ajukan_kode"></span></p>
                        <p class="mb-3">Nominal tercatat: <strong id="ajukan_total"></strong></p>
                        <div class="mb-3">
                            <label for="ajukan_alasan" class="form-label">Alasan Pembatalan <span class="text-danger">*</span></label>
                            <textarea name="alasan" id="ajukan_alasan" class="form-control" rows="3" required minlength="5" maxlength="250"
                                      placeholder="Contoh: salah klik bayar, nasabah belum membayar"></textarea>
                        </div>
                        <div class="alert alert-info mb-0">
                            <small><i class="fas fa-info-circle me-1"></i>
                            Pembayaran <strong>belum dibatalkan</strong>: kas, laporan, dan sisa tagihan tetap seperti sudah dibayar
                            sampai Admin menyetujui. Anda bisa melihat hasilnya di halaman ini.</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" name="ajukan_pembatalan" class="btn btn-warning">Kirim Pengajuan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function isiModalAngsuran(prefix, b) {
            document.getElementById(prefix + '_ke').textContent = b.getAttribute('data-angsuran_ke');
            document.getElementById(prefix + '_kode').textContent = b.getAttribute('data-kode');
            document.getElementById(prefix + '_total').textContent = 'Rp ' + Number(b.getAttribute('data-total')).toLocaleString('id-ID');
        }
        var batalModalEl = document.getElementById('batalModal');
        if (batalModalEl) {
            batalModalEl.addEventListener('show.bs.modal', function (event) {
                var b = event.relatedTarget;
                document.getElementById('batal_id').value = b.getAttribute('data-id');
                isiModalAngsuran('batal', b);
                document.getElementById('batal_alasan').value = '';
            });
        }
        var ajukanModalEl = document.getElementById('ajukanModal');
        if (ajukanModalEl) {
            ajukanModalEl.addEventListener('show.bs.modal', function (event) {
                var b = event.relatedTarget;
                document.getElementById('ajukan_id').value = b.getAttribute('data-id');
                isiModalAngsuran('ajukan', b);
                document.getElementById('ajukan_alasan').value = '';
            });
        }
        var putusModalEl = document.getElementById('putusModal');
        if (putusModalEl) {
            putusModalEl.addEventListener('show.bs.modal', function (event) {
                var b = event.relatedTarget;
                var setuju = b.getAttribute('data-keputusan') === 'setuju';
                document.getElementById('putus_id').value = b.getAttribute('data-pengajuan');
                document.getElementById('putus_keputusan').value = b.getAttribute('data-keputusan');
                isiModalAngsuran('putus', b);
                document.getElementById('putus_teller').textContent = b.getAttribute('data-teller');
                document.getElementById('putus_tanggal').textContent = b.getAttribute('data-tanggal');
                document.getElementById('putus_alasan').textContent = b.getAttribute('data-alasan');
                document.getElementById('putus_catatan').value = '';
                document.getElementById('putus_judul').textContent = setuju ? 'Setujui Pembatalan Pembayaran' : 'Tolak Pengajuan Pembatalan';
                document.getElementById('putus_judul').className = 'modal-title ' + (setuju ? 'text-success' : 'text-danger');
                var info = document.getElementById('putus_info');
                info.className = 'alert mb-0 ' + (setuju ? 'alert-warning' : 'alert-info');
                info.innerHTML = setuju
                    ? '<small><i class="fas fa-exclamation-triangle me-1"></i>Pembayaran <strong>dibatalkan</strong>: cicilan kembali belum bayar, kas dan saldo dikurangi sebesar nominal tercatat, dan sisa tagihan bertambah.</small>'
                    : '<small><i class="fas fa-info-circle me-1"></i>Angsuran tetap <strong>Lunas</strong>, tanpa perubahan saldo. Teller melihat penolakan beserta catatan Anda.</small>';
                var btn = document.getElementById('putus_submit');
                btn.className = 'btn ' + (setuju ? 'btn-success' : 'btn-danger');
                btn.textContent = setuju ? 'Setujui & Batalkan Pembayaran' : 'Tolak Pengajuan';
            });
        }
    </script>
    <script>
        // Bayar Modal
        var bayarModal = document.getElementById('bayarModal');
        bayarModal.addEventListener('show.bs.modal', function (event) {
            var button = event.relatedTarget;
            document.getElementById('bayar_id').value = button.getAttribute('data-id');
            document.getElementById('bayar_angsuran_ke').textContent = button.getAttribute('data-angsuran_ke');
            
            var total = Number(button.getAttribute('data-total'));
            document.getElementById('bayar_total').value = 'Rp ' + total.toLocaleString('id-ID');
            
            var denda = Number(button.getAttribute('data-denda'));
            document.getElementById('bayar_denda').value = denda;
            document.getElementById('bayar_denda_info').textContent = button.getAttribute('data-denda_info') || '';
        });

        // Toggle debug info dengan Ctrl+D
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'd') {
                e.preventDefault();
                var debugInfo = document.querySelector('.debug-info');
                debugInfo.style.display = debugInfo.style.display === 'none' ? 'block' : 'none';
            }
        });
    </script>
<?php if (isTeller()) { tandaiKeputusanPembatalanDilihat($db, $_SESSION['user_id']); } ?>
</body>
</html>