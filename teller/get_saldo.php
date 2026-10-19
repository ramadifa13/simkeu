<?php
require_once '../includes/auth.php';
require_once '../config/config.php';

// Start session jika belum started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

if (!isset($_GET['anggota_id']) || !isset($_GET['jenis_id'])) {
    echo json_encode(['success' => false, 'message' => 'Parameter tidak lengkap']);
    exit;
}

$anggota_id = intval($_GET['anggota_id']);
$jenis_id = intval($_GET['jenis_id']);

try {
    // Get anggota info
    $query_anggota = "SELECT kode_anggota, nama_lengkap FROM anggota WHERE id = :anggota_id";
    $stmt_anggota = $db->prepare($query_anggota);
    $stmt_anggota->bindParam(':anggota_id', $anggota_id, PDO::PARAM_INT);
    $stmt_anggota->execute();
    
    if ($stmt_anggota->rowCount() === 0) {
        echo json_encode(['success' => false, 'message' => 'Anggota tidak ditemukan']);
        exit;
    }
    
    $anggota = $stmt_anggota->fetch(PDO::FETCH_ASSOC);
    
    // Get jenis simpanan info
    $query_jenis = "SELECT nama_jenis FROM jenis_simpanan WHERE id = :jenis_id";
    $stmt_jenis = $db->prepare($query_jenis);
    $stmt_jenis->bindParam(':jenis_id', $jenis_id, PDO::PARAM_INT);
    $stmt_jenis->execute();
    
    if ($stmt_jenis->rowCount() === 0) {
        echo json_encode(['success' => false, 'message' => 'Jenis simpanan tidak ditemukan']);
        exit;
    }
    
    $jenis = $stmt_jenis->fetch(PDO::FETCH_ASSOC);
    
    // Hitung saldo dari tabel transaksi (lebih akurat)
    $query_saldo_transaksi = "SELECT 
        COALESCE(SUM(CASE WHEN jenis_transaksi = 'setoran' THEN jumlah ELSE -jumlah END), 0) as saldo_aktual,
        MAX(tanggal_transaksi) as last_transaction
        FROM transaksi 
        WHERE anggota_id = :anggota_id AND jenis_simpanan_id = :jenis_id";
    
    $stmt_saldo_transaksi = $db->prepare($query_saldo_transaksi);
    $stmt_saldo_transaksi->bindParam(':anggota_id', $anggota_id, PDO::PARAM_INT);
    $stmt_saldo_transaksi->bindParam(':jenis_id', $jenis_id, PDO::PARAM_INT);
    $stmt_saldo_transaksi->execute();
    
    $saldo_data = $stmt_saldo_transaksi->fetch(PDO::FETCH_ASSOC);
    $saldo_aktual = floatval($saldo_data['saldo_aktual']);
    $last_transaction = $saldo_data['last_transaction'];
    
    // Update tabel simpanan untuk sinkronisasi
    $query_check_simpanan = "SELECT id, saldo FROM simpanan 
                            WHERE anggota_id = :anggota_id AND jenis_simpanan_id = :jenis_id";
    $stmt_check_simpanan = $db->prepare($query_check_simpanan);
    $stmt_check_simpanan->bindParam(':anggota_id', $anggota_id, PDO::PARAM_INT);
    $stmt_check_simpanan->bindParam(':jenis_id', $jenis_id, PDO::PARAM_INT);
    $stmt_check_simpanan->execute();
    
    if ($stmt_check_simpanan->rowCount() > 0) {
        // Update saldo di tabel simpanan
        $simpanan_data = $stmt_check_simpanan->fetch(PDO::FETCH_ASSOC);
        if ($simpanan_data['saldo'] != $saldo_aktual) {
            $query_update_simpanan = "UPDATE simpanan SET saldo = :saldo, updated_at = NOW() 
                                    WHERE id = :id";
            $stmt_update_simpanan = $db->prepare($query_update_simpanan);
            $stmt_update_simpanan->bindParam(':saldo', $saldo_aktual);
            $stmt_update_simpanan->bindParam(':id', $simpanan_data['id']);
            $stmt_update_simpanan->execute();
        }
    } else {
        // Insert record baru di tabel simpanan
        $query_insert_simpanan = "INSERT INTO simpanan (anggota_id, jenis_simpanan_id, saldo) 
                                VALUES (:anggota_id, :jenis_id, :saldo)";
        $stmt_insert_simpanan = $db->prepare($query_insert_simpanan);
        $stmt_insert_simpanan->bindParam(':anggota_id', $anggota_id, PDO::PARAM_INT);
        $stmt_insert_simpanan->bindParam(':jenis_id', $jenis_id, PDO::PARAM_INT);
        $stmt_insert_simpanan->bindParam(':saldo', $saldo_aktual);
        $stmt_insert_simpanan->execute();
    }
    
    // Format response
    $last_update = $last_transaction ? date('d/m/Y H:i', strtotime($last_transaction)) : 'Belum ada transaksi';
    
    echo json_encode([
        'success' => true,
        'saldo' => $saldo_aktual,
        'saldo_formatted' => 'Rp ' . number_format($saldo_aktual, 0, ',', '.'),
        'kode_anggota' => $anggota['kode_anggota'],
        'nama_anggota' => $anggota['nama_lengkap'],
        'jenis_simpanan' => $jenis['nama_jenis'],
        'last_update' => $last_update,
        'no_record' => ($saldo_aktual == 0 && !$last_transaction)
    ]);
    
} catch (Exception $e) {
    error_log("Error in get_saldo.php: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => 'Terjadi kesalahan sistem: ' . $e->getMessage()
    ]);
}
?>