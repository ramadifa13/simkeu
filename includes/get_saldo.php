<?php
/**
 * Single implementation — API saldo simpanan (Admin & Teller).
 * Entry: admin|teller/get_saldo.php (thin wrappers).
 */
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || (!isAdmin() && !isTeller())) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

if (!isset($_GET['anggota_id']) || !isset($_GET['jenis_id'])) {
    echo json_encode(['success' => false, 'message' => 'Parameter tidak lengkap']);
    exit;
}

$anggota_id = (int)$_GET['anggota_id'];
$jenis_id = (int)$_GET['jenis_id'];

try {
    $stmt_anggota = $db->prepare('SELECT kode_anggota, nama_lengkap FROM anggota WHERE id = :anggota_id');
    $stmt_anggota->bindParam(':anggota_id', $anggota_id, PDO::PARAM_INT);
    $stmt_anggota->execute();
    if ($stmt_anggota->rowCount() === 0) {
        echo json_encode(['success' => false, 'message' => 'Anggota tidak ditemukan']);
        exit;
    }
    $anggota = $stmt_anggota->fetch(PDO::FETCH_ASSOC);

    $stmt_jenis = $db->prepare('SELECT nama_jenis FROM jenis_simpanan WHERE id = :jenis_id');
    $stmt_jenis->bindParam(':jenis_id', $jenis_id, PDO::PARAM_INT);
    $stmt_jenis->execute();
    if ($stmt_jenis->rowCount() === 0) {
        echo json_encode(['success' => false, 'message' => 'Jenis simpanan tidak ditemukan']);
        exit;
    }
    $jenis = $stmt_jenis->fetch(PDO::FETCH_ASSOC);

    // Saldo aktual dari transaksi (SSOT)
    $stmt_saldo = $db->prepare(
        "SELECT COALESCE(SUM(CASE WHEN jenis_transaksi = 'setoran' THEN jumlah ELSE -jumlah END), 0) AS saldo_aktual,
                MAX(tanggal_transaksi) AS last_transaction
         FROM transaksi
         WHERE anggota_id = :anggota_id AND jenis_simpanan_id = :jenis_id"
    );
    $stmt_saldo->bindParam(':anggota_id', $anggota_id, PDO::PARAM_INT);
    $stmt_saldo->bindParam(':jenis_id', $jenis_id, PDO::PARAM_INT);
    $stmt_saldo->execute();
    $saldo_data = $stmt_saldo->fetch(PDO::FETCH_ASSOC);
    $saldo_aktual = (float)$saldo_data['saldo_aktual'];
    $last_transaction = $saldo_data['last_transaction'];

    $stmt_check = $db->prepare(
        'SELECT id, saldo FROM simpanan WHERE anggota_id = :anggota_id AND jenis_simpanan_id = :jenis_id'
    );
    $stmt_check->bindParam(':anggota_id', $anggota_id, PDO::PARAM_INT);
    $stmt_check->bindParam(':jenis_id', $jenis_id, PDO::PARAM_INT);
    $stmt_check->execute();

    if ($stmt_check->rowCount() > 0) {
        $simpanan_data = $stmt_check->fetch(PDO::FETCH_ASSOC);
        if ((float)$simpanan_data['saldo'] !== $saldo_aktual) {
            $upd = $db->prepare('UPDATE simpanan SET saldo = :saldo, updated_at = NOW() WHERE id = :id');
            $upd->bindParam(':saldo', $saldo_aktual);
            $upd->bindParam(':id', $simpanan_data['id']);
            $upd->execute();
        }
    } else {
        $ins = $db->prepare(
            'INSERT INTO simpanan (anggota_id, jenis_simpanan_id, saldo) VALUES (:anggota_id, :jenis_id, :saldo)'
        );
        $ins->bindParam(':anggota_id', $anggota_id, PDO::PARAM_INT);
        $ins->bindParam(':jenis_id', $jenis_id, PDO::PARAM_INT);
        $ins->bindParam(':saldo', $saldo_aktual);
        $ins->execute();
    }

    $last_update = $last_transaction ? formatTanggalUi($last_transaction, true) : 'Belum ada transaksi';

    echo json_encode([
        'success' => true,
        'saldo' => $saldo_aktual,
        'saldo_formatted' => 'Rp ' . number_format($saldo_aktual, 0, ',', '.'),
        'kode_anggota' => $anggota['kode_anggota'],
        'nama_anggota' => $anggota['nama_lengkap'],
        'jenis_simpanan' => $jenis['nama_jenis'],
        'last_update' => $last_update,
        'no_record' => ($saldo_aktual == 0 && !$last_transaction),
    ]);
} catch (Exception $e) {
    error_log('Error in get_saldo: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Terjadi kesalahan sistem: ' . $e->getMessage(),
    ]);
}
