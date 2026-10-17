<?php
require_once '../includes/auth.php';
require_once '../config/config.php';

// Cek akses admin
if (!isAdmin()) {
    $_SESSION['error'] = "Anda tidak memiliki akses ke halaman ini";
    header("Location: ../login.php");
    exit();
}

$page_title = "Manajemen SHU";

// Handle form actions
if ($_POST) {
    // Hitung SHU (tahap 1: buat periode dan hitung kontribusi anggota)
    if (isset($_POST['hitung_shu'])) {
        $tahun = $_POST['tahun'];
        $nama_periode = $_POST['nama_periode'] ?? 'SHU Tahun ' . $tahun;
        $tanggal_mulai = $_POST['tanggal_mulai'] ?? $tahun . '-01-01';
        $tanggal_selesai = $_POST['tanggal_selesai'] ?? $tahun . '-12-31';
        
        try {
            $db->beginTransaction();
            
            // Cek apakah sudah ada periode untuk tahun ini
            $check_query = "SELECT id FROM shu_periode WHERE tahun = :tahun";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->bindParam(':tahun', $tahun);
            $check_stmt->execute();
            
            if ($check_stmt->rowCount() > 0) {
                throw new Exception("Periode SHU untuk tahun $tahun sudah ada");
            }
            
            // Buat periode SHU baru
            $periode_query = "INSERT INTO shu_periode (tahun, nama_periode, tanggal_mulai, tanggal_selesai, status) 
                              VALUES (:tahun, :nama_periode, :tanggal_mulai, :tanggal_selesai, 'dihitung')";
            $periode_stmt = $db->prepare($periode_query);
            $periode_stmt->bindParam(':tahun', $tahun);
            $periode_stmt->bindParam(':nama_periode', $nama_periode);
            $periode_stmt->bindParam(':tanggal_mulai', $tanggal_mulai);
            $periode_stmt->bindParam(':tanggal_selesai', $tanggal_selesai);
            $periode_stmt->execute();
            
            $periode_id = $db->lastInsertId();
            
            // Ambil data anggota aktif
            $anggota_query = "SELECT id FROM anggota WHERE status = 'aktif'";
            $anggota_stmt = $db->prepare($anggota_query);
            $anggota_stmt->execute();
            $anggota_list = $anggota_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Hitung kontribusi per anggota
            foreach ($anggota_list as $anggota) {
                // Hitung total simpanan anggota (exclude ANG)
                $simpanan_query = "SELECT COALESCE(SUM(CASE WHEN t.jenis_transaksi = 'setoran' THEN t.jumlah ELSE -t.jumlah END), 0) as total
                                   FROM transaksi t
                                   JOIN jenis_simpanan j ON t.jenis_simpanan_id = j.id
                                   WHERE t.anggota_id = :anggota_id 
                                   AND j.kode_jenis != 'ANG'
                                   AND DATE(t.tanggal_transaksi) BETWEEN :tanggal_mulai AND :tanggal_selesai";
                $simpanan_stmt = $db->prepare($simpanan_query);
                $simpanan_stmt->bindParam(':anggota_id', $anggota['id']);
                $simpanan_stmt->bindParam(':tanggal_mulai', $tanggal_mulai);
                $simpanan_stmt->bindParam(':tanggal_selesai', $tanggal_selesai);
                $simpanan_stmt->execute();
                $total_simpanan = $simpanan_stmt->fetch(PDO::FETCH_ASSOC)['total'];
                
                // Hitung total pinjaman yang pernah diambil dalam periode
                $pinjaman_query = "SELECT COALESCE(SUM(jumlah_pinjaman), 0) as total
                                   FROM pinjaman
                                   WHERE anggota_id = :anggota_id
                                   AND DATE(tanggal_pencairan) BETWEEN :tanggal_mulai AND :tanggal_selesai";
                $pinjaman_stmt = $db->prepare($pinjaman_query);
                $pinjaman_stmt->bindParam(':anggota_id', $anggota['id']);
                $pinjaman_stmt->bindParam(':tanggal_mulai', $tanggal_mulai);
                $pinjaman_stmt->bindParam(':tanggal_selesai', $tanggal_selesai);
                $pinjaman_stmt->execute();
                $total_pinjaman = $pinjaman_stmt->fetch(PDO::FETCH_ASSOC)['total'];
                
                // Hitung total angsuran yang sudah dibayar dalam periode
                $angsuran_query = "SELECT COALESCE(SUM(a.jumlah_angsuran + COALESCE(a.denda, 0)), 0) as total
                                   FROM angsuran a
                                   JOIN pinjaman p ON a.pinjaman_id = p.id
                                   WHERE p.anggota_id = :anggota_id
                                   AND a.tanggal_bayar IS NOT NULL
                                   AND DATE(a.tanggal_bayar) BETWEEN :tanggal_mulai AND :tanggal_selesai";
                $angsuran_stmt = $db->prepare($angsuran_query);
                $angsuran_stmt->bindParam(':anggota_id', $anggota['id']);
                $angsuran_stmt->bindParam(':tanggal_mulai', $tanggal_mulai);
                $angsuran_stmt->bindParam(':tanggal_selesai', $tanggal_selesai);
                $angsuran_stmt->execute();
                $total_angsuran = $angsuran_stmt->fetch(PDO::FETCH_ASSOC)['total'];
                
                // Hitung total transaksi (simpanan + angsuran)
                $total_transaksi = $total_simpanan + $total_angsuran;
                
                // Simpan ke tabel shu_anggota
                $shu_query = "INSERT INTO shu_anggota (shu_periode_id, anggota_id, total_simpanan, 
                              total_pinjaman, total_angsuran, total_transaksi)
                              VALUES (:periode_id, :anggota_id, :total_simpanan, :total_pinjaman, 
                              :total_angsuran, :total_transaksi)";
                $shu_stmt = $db->prepare($shu_query);
                $shu_stmt->bindParam(':periode_id', $periode_id);
                $shu_stmt->bindParam(':anggota_id', $anggota['id']);
                $shu_stmt->bindParam(':total_simpanan', $total_simpanan);
                $shu_stmt->bindParam(':total_pinjaman', $total_pinjaman);
                $shu_stmt->bindParam(':total_angsuran', $total_angsuran);
                $shu_stmt->bindParam(':total_transaksi', $total_transaksi);
                $shu_stmt->execute();
            }
            
            $db->commit();
            $_SESSION['success'] = "Perhitungan kontribusi SHU tahun $tahun berhasil dibuat. Silakan lanjutkan ke pembagian SHU.";
        } catch (Exception $e) {
            $db->rollBack();
            $_SESSION['error'] = "Gagal menghitung SHU: " . $e->getMessage();
        }
        
        header("Location: shu.php");
        exit();
    }
    
    // Proses pembagian SHU (tahap 2: hitung jasa berdasarkan persentase)
    if (isset($_POST['proses_pembagian'])) {
        $periode_id = $_POST['periode_id'];
        $total_shu = str_replace(['.', ','], '', $_POST['total_shu']);
        
        // Ambil pengaturan persentase untuk tahun tersebut
        $periode_info_query = "SELECT tahun FROM shu_periode WHERE id = :periode_id";
        $periode_info_stmt = $db->prepare($periode_info_query);
        $periode_info_stmt->bindParam(':periode_id', $periode_id);
        $periode_info_stmt->execute();
        $periode_info = $periode_info_stmt->fetch(PDO::FETCH_ASSOC);
        
        $pengaturan_query = "SELECT * FROM shu_pengaturan WHERE tahun = :tahun";
        $pengaturan_stmt = $db->prepare($pengaturan_query);
        $pengaturan_stmt->bindParam(':tahun', $periode_info['tahun']);
        $pengaturan_stmt->execute();
        $pengaturan = $pengaturan_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$pengaturan) {
            $_SESSION['error'] = "Pengaturan SHU untuk tahun " . $periode_info['tahun'] . " tidak ditemukan";
            header("Location: shu.php");
            exit();
        }
        
        try {
            $db->beginTransaction();
            
            // Hitung total simpanan, pinjaman, transaksi dari semua anggota
            $total_query = "SELECT 
                            COALESCE(SUM(total_simpanan), 0) as total_simpanan,
                            COALESCE(SUM(total_pinjaman), 0) as total_pinjaman,
                            COALESCE(SUM(total_transaksi), 0) as total_transaksi
                            FROM shu_anggota WHERE shu_periode_id = :periode_id";
            $total_stmt = $db->prepare($total_query);
            $total_stmt->bindParam(':periode_id', $periode_id);
            $total_stmt->execute();
            $total = $total_stmt->fetch(PDO::FETCH_ASSOC);
            
            // Hitung alokasi per jenis jasa
            $jasa_simpanan = $total_shu * $pengaturan['persen_jasa_simpanan'] / 100;
            $jasa_pinjaman = $total_shu * $pengaturan['persen_jasa_pinjaman'] / 100;
            $jasa_transaksi = $total_shu * $pengaturan['persen_jasa_transaksi'] / 100;
            
            // Hitung per anggota
            $anggota_query = "SELECT * FROM shu_anggota WHERE shu_periode_id = :periode_id";
            $anggota_stmt = $db->prepare($anggota_query);
            $anggota_stmt->bindParam(':periode_id', $periode_id);
            $anggota_stmt->execute();
            $anggota_shu = $anggota_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($anggota_shu as $a) {
                $jasa_simpanan_anggota = 0;
                $jasa_pinjaman_anggota = 0;
                $jasa_transaksi_anggota = 0;
                
                // Jasa simpanan berdasarkan proporsi simpanan
                if ($total['total_simpanan'] > 0) {
                    $jasa_simpanan_anggota = $jasa_simpanan * ($a['total_simpanan'] / $total['total_simpanan']);
                }
                
                // Jasa pinjaman berdasarkan proporsi pinjaman
                if ($total['total_pinjaman'] > 0) {
                    $jasa_pinjaman_anggota = $jasa_pinjaman * ($a['total_pinjaman'] / $total['total_pinjaman']);
                }
                
                // Jasa transaksi berdasarkan proporsi transaksi
                if ($total['total_transaksi'] > 0) {
                    $jasa_transaksi_anggota = $jasa_transaksi * ($a['total_transaksi'] / $total['total_transaksi']);
                }
                
                $total_shu_anggota = $jasa_simpanan_anggota + $jasa_pinjaman_anggota + $jasa_transaksi_anggota;
                
                $update_query = "UPDATE shu_anggota SET 
                                 jasa_simpanan = :jasa_simpanan,
                                 jasa_pinjaman = :jasa_pinjaman,
                                 jasa_transaksi = :jasa_transaksi,
                                 total_shu = :total_shu
                                 WHERE id = :id";
                $update_stmt = $db->prepare($update_query);
                $update_stmt->bindParam(':jasa_simpanan', $jasa_simpanan_anggota);
                $update_stmt->bindParam(':jasa_pinjaman', $jasa_pinjaman_anggota);
                $update_stmt->bindParam(':jasa_transaksi', $jasa_transaksi_anggota);
                $update_stmt->bindParam(':total_shu', $total_shu_anggota);
                $update_stmt->bindParam(':id', $a['id']);
                $update_stmt->execute();
            }
            
            // Update periode dengan total SHU dan status final
            $update_periode = "UPDATE shu_periode SET total_shu = :total_shu, status = 'final' WHERE id = :id";
            $update_periode_stmt = $db->prepare($update_periode);
            $update_periode_stmt->bindParam(':total_shu', $total_shu);
            $update_periode_stmt->bindParam(':id', $periode_id);
            $update_periode_stmt->execute();
            
            $db->commit();
            $_SESSION['success'] = "Pembagian SHU berhasil diproses";
        } catch (Exception $e) {
            $db->rollBack();
            $_SESSION['error'] = "Gagal memproses pembagian SHU: " . $e->getMessage();
        }
        
        header("Location: shu.php");
        exit();
    }
    
    // Update pengaturan SHU
    if (isset($_POST['update_pengaturan'])) {
        $tahun = $_POST['tahun'];
        $persen_jasa_simpanan = $_POST['persen_jasa_simpanan'];
        $persen_jasa_pinjaman = $_POST['persen_jasa_pinjaman'];
        $persen_jasa_transaksi = $_POST['persen_jasa_transaksi'];
        $persen_cadangan = $_POST['persen_cadangan'];
        $persen_pengurus = $_POST['persen_pengurus'];
        $persen_pengawas = $_POST['persen_pengawas'];
        $persen_dana_sosial = $_POST['persen_dana_sosial'];
        $persen_pendidikan = $_POST['persen_pendidikan'];
        
        // Validasi total persentase
        $total_persen = $persen_jasa_simpanan + $persen_jasa_pinjaman + $persen_jasa_transaksi + 
                        $persen_cadangan + $persen_pengurus + $persen_pengawas + 
                        $persen_dana_sosial + $persen_pendidikan;
        
        if ($total_persen != 100) {
            $_SESSION['error'] = "Total persentase harus 100% (saat ini $total_persen%)";
            header("Location: shu.php");
            exit();
        }
        
        try {
            $query = "INSERT INTO shu_pengaturan (tahun, persen_jasa_simpanan, persen_jasa_pinjaman, 
                      persen_jasa_transaksi, persen_cadangan, persen_pengurus, persen_pengawas, 
                      persen_dana_sosial, persen_pendidikan) 
                      VALUES (:tahun, :jasa_simpanan, :jasa_pinjaman, :jasa_transaksi, :cadangan, 
                      :pengurus, :pengawas, :dana_sosial, :pendidikan)
                      ON DUPLICATE KEY UPDATE
                      persen_jasa_simpanan = :jasa_simpanan,
                      persen_jasa_pinjaman = :jasa_pinjaman,
                      persen_jasa_transaksi = :jasa_transaksi,
                      persen_cadangan = :cadangan,
                      persen_pengurus = :pengurus,
                      persen_pengawas = :pengawas,
                      persen_dana_sosial = :dana_sosial,
                      persen_pendidikan = :pendidikan";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':tahun', $tahun);
            $stmt->bindParam(':jasa_simpanan', $persen_jasa_simpanan);
            $stmt->bindParam(':jasa_pinjaman', $persen_jasa_pinjaman);
            $stmt->bindParam(':jasa_transaksi', $persen_jasa_transaksi);
            $stmt->bindParam(':cadangan', $persen_cadangan);
            $stmt->bindParam(':pengurus', $persen_pengurus);
            $stmt->bindParam(':pengawas', $persen_pengawas);
            $stmt->bindParam(':dana_sosial', $persen_dana_sosial);
            $stmt->bindParam(':pendidikan', $persen_pendidikan);
            $stmt->execute();
            
            $_SESSION['success'] = "Pengaturan SHU tahun $tahun berhasil disimpan";
        } catch (Exception $e) {
            $_SESSION['error'] = "Gagal menyimpan pengaturan: " . $e->getMessage();
        }
        
        header("Location: shu.php");
        exit();
    }
    
    // Hapus periode SHU (jika masih draft/dihitung)
    if (isset($_POST['hapus_periode'])) {
        $periode_id = $_POST['periode_id'];
        
        try {
            // Cek status periode
            $check_query = "SELECT status FROM shu_periode WHERE id = :id";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->bindParam(':id', $periode_id);
            $check_stmt->execute();
            $periode = $check_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($periode['status'] == 'final') {
                throw new Exception("Periode dengan status FINAL tidak dapat dihapus");
            }
            
            $db->beginTransaction();
            
            // Hapus data SHU anggota (foreign key cascade akan menghapus otomatis)
            $delete_query = "DELETE FROM shu_periode WHERE id = :id";
            $delete_stmt = $db->prepare($delete_query);
            $delete_stmt->bindParam(':id', $periode_id);
            $delete_stmt->execute();
            
            $db->commit();
            $_SESSION['success'] = "Periode SHU berhasil dihapus";
        } catch (Exception $e) {
            $db->rollBack();
            $_SESSION['error'] = "Gagal menghapus periode: " . $e->getMessage();
        }
        
        header("Location: shu.php");
        exit();
    }
}

// Get all SHU periods
$periode_query = "SELECT * FROM shu_periode ORDER BY tahun DESC";
$periode_stmt = $db->prepare($periode_query);
$periode_stmt->execute();
$periode_list = $periode_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get pengaturan untuk tahun berjalan
$tahun_sekarang = date('Y');
$pengaturan_query = "SELECT * FROM shu_pengaturan WHERE tahun = :tahun";
$pengaturan_stmt = $db->prepare($pengaturan_query);
$pengaturan_stmt->bindParam(':tahun', $tahun_sekarang);
$pengaturan_stmt->execute();
$pengaturan = $pengaturan_stmt->fetch(PDO::FETCH_ASSOC);

if (!$pengaturan) {
    $pengaturan = [
        'tahun' => $tahun_sekarang,
        'persen_jasa_simpanan' => 30,
        'persen_jasa_pinjaman' => 30,
        'persen_jasa_transaksi' => 10,
        'persen_cadangan' => 10,
        'persen_pengurus' => 10,
        'persen_pengawas' => 5,
        'persen_dana_sosial' => 3,
        'persen_pendidikan' => 2
    ];
}

// Get detail SHU jika ada periode yang dipilih
$detail_shu = [];
$selected_periode = $_GET['periode_id'] ?? ($periode_list[0]['id'] ?? 0);
if ($selected_periode) {
    $detail_query = "SELECT sa.*, a.kode_anggota, a.nama_lengkap 
                     FROM shu_anggota sa
                     JOIN anggota a ON sa.anggota_id = a.id
                     WHERE sa.shu_periode_id = :periode_id
                     ORDER BY sa.total_shu DESC";
    $detail_stmt = $db->prepare($detail_query);
    $detail_stmt->bindParam(':periode_id', $selected_periode);
    $detail_stmt->execute();
    $detail_shu = $detail_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Hitung total SHU untuk periode yang dipilih
$total_shu_periode = array_sum(array_column($detail_shu, 'total_shu'));

// Fungsi format rupiah
function formatRupiah($angka) {
    if ($angka == 0) return 'Rp 0';
    return 'Rp ' . number_format($angka, 0, ',', '.');
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .card {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            box-shadow: none;
            margin-bottom: 20px;
        }
        .card-header {
            background-color: #f8f9fa;
            border-bottom: 1px solid #dee2e6;
            color: #333;
            font-weight: 600;
            padding: 12px 16px;
        }
        .card-body {
            padding: 16px;
        }
        .table {
            margin-bottom: 0;
        }
        .table th {
            background-color: #f8f9fa;
            border-top: none;
        }
        .status-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.85rem;
            font-weight: normal;
        }
        .bg-draft { background-color: #6c757d; color: white; }
        .bg-dihitung { background-color: #ffc107; color: #212529; }
        .bg-final { background-color: #28a745; color: white; }
        .btn-sm { padding: 0.25rem 0.5rem; font-size: 0.875rem; }
        .list-group-item.active {
            background-color: #007bff;
            border-color: #007bff;
        }
        .summary-box {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }
        .summary-label {
            color: #6c757d;
            font-size: 0.9rem;
        }
        .summary-value {
            font-size: 1.5rem;
            font-weight: 600;
        }
        .nav-tabs .nav-link {
            color: #495057;
            border: 1px solid transparent;
        }
        .nav-tabs .nav-link.active {
            color: #007bff;
            border-color: #dee2e6 #dee2e6 #fff;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="container-fluid">
        <div class="row">
            <?php include '../includes/sidebar.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h1 class="h3">
                        <i class="fas fa-calculator me-2"></i>Manajemen SHU (Sisa Hasil Usaha)
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

                <!-- Tabs -->
                <ul class="nav nav-tabs mb-4" id="shuTabs">
                    <li class="nav-item">
                        <a class="nav-link active" id="periode-tab" data-bs-toggle="tab" href="#periode">Periode SHU</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" id="hitung-tab" data-bs-toggle="tab" href="#hitung">Hitung SHU Baru</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" id="pengaturan-tab" data-bs-toggle="tab" href="#pengaturan">Pengaturan Persentase</a>
                    </li>
                </ul>

                <div class="tab-content">
                    <!-- Tab Periode SHU -->
                    <div class="tab-pane fade show active" id="periode">
                        <div class="row">
                            <div class="col-md-4">
                                <div class="card">
                                    <div class="card-header">
                                        <i class="fas fa-list me-2"></i>Daftar Periode SHU
                                    </div>
                                    <div class="card-body p-0">
                                        <div class="list-group list-group-flush">
                                            <?php if (empty($periode_list)): ?>
                                                <div class="text-center py-4">
                                                    <p class="text-muted mb-2">Belum ada periode SHU</p>
                                                    <a href="#" onclick="document.querySelector('#hitung-tab').click(); return false;" class="btn btn-primary btn-sm">
                                                        Hitung SHU Baru
                                                    </a>
                                                </div>
                                            <?php else: ?>
                                                <?php foreach ($periode_list as $p): ?>
                                                <a href="?periode_id=<?php echo $p['id']; ?>" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center <?php echo $selected_periode == $p['id'] ? 'active' : ''; ?>">
                                                    <div>
                                                        <strong><?php echo $p['nama_periode']; ?></strong><br>
                                                        <small><?php echo date('d/m/Y', strtotime($p['tanggal_mulai'])); ?> - <?php echo date('d/m/Y', strtotime($p['tanggal_selesai'])); ?></small>
                                                    </div>
                                                    <div>
                                                        <span class="badge <?php 
                                                            echo $p['status'] == 'final' ? 'bg-final' : ($p['status'] == 'dihitung' ? 'bg-dihitung' : 'bg-draft'); 
                                                        ?> status-badge">
                                                            <?php echo ucfirst($p['status']); ?>
                                                        </span>
                                                    </div>
                                                </a>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-8">
                                <?php if (!empty($detail_shu)): 
                                    $periode_info = null;
                                    foreach ($periode_list as $p) {
                                        if ($p['id'] == $selected_periode) {
                                            $periode_info = $p;
                                            break;
                                        }
                                    }
                                ?>
                                <div class="card">
                                    <div class="card-header d-flex justify-content-between align-items-center">
                                        <div>
                                            <i class="fas fa-chart-pie me-2"></i>Detail SHU - <?php echo $periode_info['nama_periode']; ?>
                                        </div>
                                        <div>
                                            <?php if ($periode_info['status'] == 'dihitung'): ?>
                                            <button class="btn btn-success btn-sm me-2" data-bs-toggle="modal" data-bs-target="#prosesModal" 
                                                    data-periode-id="<?php echo $periode_info['id']; ?>">
                                                <i class="fas fa-calculator me-1"></i> Proses Pembagian
                                            </button>
                                            <?php endif; ?>
                                            <?php if ($periode_info['status'] != 'final'): ?>
                                            <button class="btn btn-danger btn-sm" onclick="hapusPeriode(<?php echo $periode_info['id']; ?>, '<?php echo $periode_info['nama_periode']; ?>')">
                                                <i class="fas fa-trash me-1"></i> Hapus
                                            </button>
                                            <?php endif; ?>
                                            <a href="shu_anggota.php?periode_id=<?php echo $periode_info['id']; ?>" class="btn btn-info btn-sm">
                                                <i class="fas fa-users me-1"></i> Kelola Pembayaran
                                            </a>
                                        </div>
                                    </div>
                                    <div class="card-body">
                                        <div class="row mb-3">
                                            <div class="col-md-6">
                                                <div class="summary-box">
                                                    <div class="summary-label">Total SHU</div>
                                                    <div class="summary-value text-primary"><?php echo formatRupiah($periode_info['total_shu']); ?></div>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="summary-box">
                                                    <div class="summary-label">Status</div>
                                                    <div class="summary-value">
                                                        <span class="badge <?php 
                                                            echo $periode_info['status'] == 'final' ? 'bg-final' : ($periode_info['status'] == 'dihitung' ? 'bg-dihitung' : 'bg-draft'); 
                                                        ?> status-badge" style="font-size: 1rem;">
                                                            <?php echo ucfirst($periode_info['status']); ?>
                                                        </span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="table-responsive">
                                            <table class="table table-striped table-hover">
                                                <thead>
                                                    <tr>
                                                        <th>No</th>
                                                        <th>Kode</th>
                                                        <th>Nama Anggota</th>
                                                        <th class="text-end">Jasa Simpanan</th>
                                                        <th class="text-end">Jasa Pinjaman</th>
                                                        <th class="text-end">Jasa Transaksi</th>
                                                        <th class="text-end">Total SHU</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php 
                                                    $no = 1;
                                                    $total_tampil = 0;
                                                    foreach ($detail_shu as $d): 
                                                        $total_tampil += $d['total_shu'];
                                                    ?>
                                                    <tr>
                                                        <td><?php echo $no++; ?></td>
                                                        <td><?php echo $d['kode_anggota']; ?></td>
                                                        <td><?php echo $d['nama_lengkap']; ?></td>
                                                        <td class="text-end"><?php echo formatRupiah($d['jasa_simpanan']); ?></td>
                                                        <td class="text-end"><?php echo formatRupiah($d['jasa_pinjaman']); ?></td>
                                                        <td class="text-end"><?php echo formatRupiah($d['jasa_transaksi']); ?></td>
                                                        <td class="text-end fw-bold text-success"><?php echo formatRupiah($d['total_shu']); ?></td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                                <tfoot class="table-light">
                                                    <tr>
                                                        <th colspan="6" class="text-end">TOTAL</th>
                                                        <th class="text-end"><?php echo formatRupiah($total_tampil); ?></th>
                                                    </tr>
                                                </tfoot>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                                <?php else: ?>
                                <div class="card">
                                    <div class="card-body text-center py-5">
                                        <i class="fas fa-chart-pie fa-3x text-muted mb-3"></i>
                                        <h5 class="text-muted">Belum ada data SHU</h5>
                                        <p class="text-muted mb-3">Pilih periode SHU di samping atau hitung SHU baru</p>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Tab Hitung SHU Baru -->
                    <div class="tab-pane fade" id="hitung">
                        <div class="card">
                            <div class="card-header">
                                <i class="fas fa-plus-circle me-2"></i>Hitung SHU Periode Baru
                            </div>
                            <div class="card-body">
                                <form method="POST">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Tahun</label>
                                                <select name="tahun" class="form-control" required>
                                                    <?php for ($y = date('Y') - 2; $y <= date('Y'); $y++): ?>
                                                    <option value="<?php echo $y; ?>" <?php echo $y == date('Y') ? 'selected' : ''; ?>>
                                                        <?php echo $y; ?>
                                                    </option>
                                                    <?php endfor; ?>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Nama Periode</label>
                                                <input type="text" name="nama_periode" class="form-control" 
                                                       value="SHU Tahun <?php echo date('Y'); ?>" required>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Tanggal Mulai</label>
                                                <input type="date" name="tanggal_mulai" class="form-control" 
                                                       value="<?php echo date('Y-01-01'); ?>" required>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Tanggal Selesai</label>
                                                <input type="date" name="tanggal_selesai" class="form-control" 
                                                       value="<?php echo date('Y-12-31'); ?>" required>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="alert alert-warning">
                                        <i class="fas fa-info-circle me-2"></i>
                                        Perhitungan SHU tahap 1 akan mengumpulkan data kontribusi anggota (simpanan, pinjaman, transaksi) dalam periode yang ditentukan.
                                    </div>
                                    <button type="submit" name="hitung_shu" class="btn btn-primary">
                                        <i class="fas fa-calculator me-2"></i> Hitung Kontribusi Anggota
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Tab Pengaturan Persentase -->
                    <div class="tab-pane fade" id="pengaturan">
                        <div class="card">
                            <div class="card-header">
                                <i class="fas fa-sliders-h me-2"></i>Pengaturan Persentase SHU
                            </div>
                            <div class="card-body">
                                <form method="POST">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Tahun</label>
                                                <select name="tahun" class="form-control" required>
                                                    <?php for ($y = date('Y') - 2; $y <= date('Y') + 1; $y++): ?>
                                                    <option value="<?php echo $y; ?>" <?php echo $y == $pengaturan['tahun'] ? 'selected' : ''; ?>>
                                                        <?php echo $y; ?>
                                                    </option>
                                                    <?php endfor; ?>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label class="form-label">Jasa Simpanan (%)</label>
                                                <input type="number" name="persen_jasa_simpanan" class="form-control" 
                                                       step="0.01" min="0" max="100" 
                                                       value="<?php echo $pengaturan['persen_jasa_simpanan']; ?>" required>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label class="form-label">Jasa Pinjaman (%)</label>
                                                <input type="number" name="persen_jasa_pinjaman" class="form-control" 
                                                       step="0.01" min="0" max="100" 
                                                       value="<?php echo $pengaturan['persen_jasa_pinjaman']; ?>" required>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label class="form-label">Jasa Transaksi (%)</label>
                                                <input type="number" name="persen_jasa_transaksi" class="form-control" 
                                                       step="0.01" min="0" max="100" 
                                                       value="<?php echo $pengaturan['persen_jasa_transaksi']; ?>" required>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label class="form-label">Cadangan (%)</label>
                                                <input type="number" name="persen_cadangan" class="form-control" 
                                                       step="0.01" min="0" max="100" 
                                                       value="<?php echo $pengaturan['persen_cadangan']; ?>" required>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label class="form-label">Pengurus (%)</label>
                                                <input type="number" name="persen_pengurus" class="form-control" 
                                                       step="0.01" min="0" max="100" 
                                                       value="<?php echo $pengaturan['persen_pengurus']; ?>" required>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label class="form-label">Pengawas (%)</label>
                                                <input type="number" name="persen_pengawas" class="form-control" 
                                                       step="0.01" min="0" max="100" 
                                                       value="<?php echo $pengaturan['persen_pengawas']; ?>" required>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label class="form-label">Dana Sosial (%)</label>
                                                <input type="number" name="persen_dana_sosial" class="form-control" 
                                                       step="0.01" min="0" max="100" 
                                                       value="<?php echo $pengaturan['persen_dana_sosial']; ?>" required>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label class="form-label">Pendidikan (%)</label>
                                                <input type="number" name="persen_pendidikan" class="form-control" 
                                                       step="0.01" min="0" max="100" 
                                                       value="<?php echo $pengaturan['persen_pendidikan']; ?>" required>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="alert alert-info" id="totalPersentase">
                                        Total: <strong>100%</strong>
                                    </div>
                                    <button type="submit" name="update_pengaturan" class="btn btn-primary">
                                        <i class="fas fa-save me-2"></i> Simpan Pengaturan
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Modal Proses Pembagian SHU -->
    <div class="modal fade" id="prosesModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header bg-success text-white">
                        <h5 class="modal-title">Proses Pembagian SHU</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="periode_id" id="modal_periode_id">
                        <div class="mb-3">
                            <label class="form-label">Total SHU yang Akan Dibagikan (Rp)</label>
                            <input type="text" name="total_shu" class="form-control" required 
                                   placeholder="Contoh: 10000000" id="modal_total_shu">
                            <small class="text-muted">Masukkan total SHU yang akan dibagikan ke anggota</small>
                        </div>
                        <div class="alert alert-warning">
                            <i class="fas fa-info-circle me-2"></i>
                            Pembagian SHU akan dihitung berdasarkan persentase yang sudah ditentukan.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" name="proses_pembagian" class="btn btn-success">
                            <i class="fas fa-check me-1"></i> Proses
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Hapus Periode -->
    <div class="modal fade" id="hapusModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title">Hapus Periode SHU</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="periode_id" id="hapus_periode_id">
                        <p>Apakah Anda yakin ingin menghapus periode:</p>
                        <p class="fw-bold" id="hapus_nama_periode"></p>
                        <p class="text-danger small">
                            <i class="fas fa-info-circle me-1"></i>
                            Hanya periode dengan status DRAFT atau DIHITUNG yang dapat dihapus.
                        </p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" name="hapus_periode" class="btn btn-danger">
                            <i class="fas fa-trash me-1"></i> Hapus
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Hitung total persentase
        function hitungTotalPersentase() {
            const inputs = document.querySelectorAll('#pengaturan input[type="number"]');
            let total = 0;
            inputs.forEach(input => {
                total += parseFloat(input.value) || 0;
            });
            
            const totalElement = document.getElementById('totalPersentase');
            if (total === 100) {
                totalElement.className = 'alert alert-success';
                totalElement.innerHTML = 'Total: <strong>' + total + '% (Valid)</strong>';
            } else {
                totalElement.className = 'alert alert-danger';
                totalElement.innerHTML = 'Total: <strong>' + total + '% (Harus 100%)</strong>';
            }
        }
        
        // Event listeners untuk input persentase
        document.querySelectorAll('#pengaturan input[type="number"]').forEach(input => {
            input.addEventListener('input', hitungTotalPersentase);
        });
        
        // Set modal data untuk proses pembagian
        document.getElementById('prosesModal').addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const periodeId = button.getAttribute('data-periode-id');
            document.getElementById('modal_periode_id').value = periodeId;
        });
        
        // Fungsi hapus periode
        function hapusPeriode(id, nama) {
            document.getElementById('hapus_periode_id').value = id;
            document.getElementById('hapus_nama_periode').textContent = nama;
            
            var hapusModal = new bootstrap.Modal(document.getElementById('hapusModal'));
            hapusModal.show();
        }
    </script>
</body>
</html>