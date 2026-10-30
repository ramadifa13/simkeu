<?php
/**
 * Single implementation — Detail Anggota (Admin & Teller).
 * Entry: admin|teller/detail_anggota.php (thin wrappers).
 */
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

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $_SESSION['error'] = "ID anggota tidak valid!";
    header("Location: anggota.php");
    exit();
}

$id = $_GET['id'];

// Get member data with ALL savings information
$query = "SELECT a.*, u.username
          FROM anggota a 
          LEFT JOIN users u ON a.user_id = u.id 
          WHERE a.id = :id";
$stmt = $db->prepare($query);
$stmt->bindParam(':id', $id);
$stmt->execute();
$member = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$member) {
    $_SESSION['error'] = "Anggota tidak ditemukan!";
    header("Location: anggota.php");
    exit();
}

// Get ALL savings data with calculation from transactions
$savings_query = "SELECT 
    js.id,
    js.nama_jenis,
    js.kode_jenis,
    COALESCE(s.saldo, 0) as saldo_simpanan,
    COALESCE((
        SELECT COALESCE(SUM(CASE WHEN jenis_transaksi = 'setoran' THEN jumlah ELSE -jumlah END), 0)
        FROM transaksi t 
        WHERE t.anggota_id = :id_transaksi AND t.jenis_simpanan_id = js.id
    ), 0) as saldo_transaksi
FROM jenis_simpanan js
LEFT JOIN simpanan s ON js.id = s.jenis_simpanan_id AND s.anggota_id = :id_simpanan
WHERE js.status = 'aktif'
ORDER BY js.id";
$stmt_savings = $db->prepare($savings_query);
$stmt_savings->bindValue(':id_transaksi', $id, PDO::PARAM_INT);
$stmt_savings->bindValue(':id_simpanan', $id, PDO::PARAM_INT);
$stmt_savings->execute();
$savings_data = $stmt_savings->fetchAll(PDO::FETCH_ASSOC);

// Sinkronisasi dan update saldo yang tidak sesuai
foreach ($savings_data as $saving) {
    if ($saving['saldo_simpanan'] != $saving['saldo_transaksi']) {
        // Update saldo di tabel simpanan
        $update_query = "INSERT INTO simpanan (anggota_id, jenis_simpanan_id, saldo, updated_at) 
                        VALUES (:anggota_id, :jenis_id, :saldo, NOW())
                        ON DUPLICATE KEY UPDATE saldo = :saldo_update, updated_at = NOW()";
        $stmt_update = $db->prepare($update_query);
        $stmt_update->bindValue(':anggota_id', $id, PDO::PARAM_INT);
        $stmt_update->bindValue(':jenis_id', $saving['id'], PDO::PARAM_INT);
        $stmt_update->bindValue(':saldo', $saving['saldo_transaksi']);
        $stmt_update->bindValue(':saldo_update', $saving['saldo_transaksi']);
        $stmt_update->execute();
    }
}

// Get updated savings data after synchronization
$final_savings_query = "SELECT 
    js.id,
    js.nama_jenis,
    js.kode_jenis,
    COALESCE(s.saldo, 0) as saldo
FROM jenis_simpanan js
LEFT JOIN simpanan s ON js.id = s.jenis_simpanan_id AND s.anggota_id = :id
WHERE js.status = 'aktif'
ORDER BY js.id";
$stmt_final = $db->prepare($final_savings_query);
$stmt_final->bindParam(':id', $id);
$stmt_final->execute();
$final_savings = $stmt_final->fetchAll(PDO::FETCH_ASSOC);

// Organize savings by type
$simpanan_pokok = 0;
$simpanan_wajib = 0;
$simpanan_sukarela = 0;
$simpanan_lainnya = [];

foreach ($final_savings as $saving) {
    switch ($saving['kode_jenis']) {
        case 'POKOK':
            $simpanan_pokok = $saving['saldo'];
            break;
        case 'WAJIB':
            $simpanan_wajib = $saving['saldo'];
            break;
        case 'SUKARELA':
            $simpanan_sukarela = $saving['saldo'];
            break;
        default:
            $simpanan_lainnya[] = [
                'nama' => $saving['nama_jenis'],
                'saldo' => $saving['saldo']
            ];
            break;
    }
}

$total_simpanan = $simpanan_pokok + $simpanan_wajib + $simpanan_sukarela + 
                  array_sum(array_column($simpanan_lainnya, 'saldo'));

// Get loan summary with bunga
$loan_query = "SELECT 
    COUNT(*) as total_pinjaman, 
    COALESCE(SUM(jumlah_pinjaman), 0) as total_jumlah,
    COALESCE(SUM(sisa_pokok), 0) as total_sisa,
    COALESCE(SUM(total_pinjaman - jumlah_pinjaman), 0) as total_bunga
FROM pinjaman 
WHERE anggota_id = :id AND status = 'aktif'";
$stmt_loan = $db->prepare($loan_query);
$stmt_loan->bindParam(':id', $id);
$stmt_loan->execute();
$loan_summary = $stmt_loan->fetch(PDO::FETCH_ASSOC);

// Hitung total yang harus dibayar (pokok + bunga)
$total_harus_dibayar = $loan_summary['total_jumlah'] + $loan_summary['total_bunga'];

// Get recent transactions from tabungan
$recent_transactions_query = "SELECT 
    t.kode_transaksi,
    t.jenis_transaksi,
    t.jumlah,
    t.tanggal_transaksi,
    js.nama_jenis,
    u.nama_lengkap as teller,
    'Tabungan' as sumber
FROM transaksi t
JOIN jenis_simpanan js ON t.jenis_simpanan_id = js.id
JOIN users u ON t.user_id = u.id
WHERE t.anggota_id = :id
ORDER BY t.tanggal_transaksi DESC
LIMIT 5";
$stmt_transactions = $db->prepare($recent_transactions_query);
$stmt_transactions->bindParam(':id', $id);
$stmt_transactions->execute();
$recent_transactions = $stmt_transactions->fetchAll(PDO::FETCH_ASSOC);

// Get recent waserda transactions
$recent_waserda_query = "SELECT 
    w.kode_transaksi,
    'waserda' as jenis_transaksi,
    w.total_bayar as jumlah,
    w.created_at as tanggal_transaksi,
    'Waserda' as nama_jenis,
    u.nama_lengkap as teller,
    'Waserda' as sumber,
    w.items
FROM waserda_transaksi w
LEFT JOIN users u ON w.user_id = u.id
WHERE w.anggota_id = :id
ORDER BY w.created_at DESC
LIMIT 5";
$stmt_waserda = $db->prepare($recent_waserda_query);
$stmt_waserda->bindParam(':id', $id);
$stmt_waserda->execute();
$recent_waserda = $stmt_waserda->fetchAll(PDO::FETCH_ASSOC);

// Process waserda items untuk ditampilkan
foreach ($recent_waserda as &$w) {
    $items = json_decode($w['items'], true);
    $jml_item = is_array($items) ? count($items) : 1;
    $nama_barang = is_array($items) ? $items[0]['nama'] : 'barang';
    $w['keterangan'] = $nama_barang . ' (' . $jml_item . ' item)';
}

// Gabungkan semua transaksi (tabungan + waserda)
$all_transactions = array_merge($recent_transactions, $recent_waserda);

// Urutkan berdasarkan tanggal (terbaru ke terlama)
usort($all_transactions, function($a, $b) {
    return strtotime($b['tanggal_transaksi']) - strtotime($a['tanggal_transaksi']);
});

// Ambil 5 terbaru
$recent_transactions = array_slice($all_transactions, 0, 5);

// Get active loans with detailed information
$active_loans_query = "SELECT 
    p.kode_pinjaman,
    p.jumlah_pinjaman,
    p.sisa_pokok,
    p.total_pinjaman,
    p.tanggal_pencairan,
    p.jatuh_tempo,
    jp.nama_jenis,
    p.bunga,
    p.tenor
FROM pinjaman p
JOIN jenis_pinjaman jp ON p.jenis_pinjaman_id = jp.id
WHERE p.anggota_id = :id AND p.status = 'aktif'
ORDER BY p.tanggal_pencairan DESC";
$stmt_loans = $db->prepare($active_loans_query);
$stmt_loans->bindParam(':id', $id);
$stmt_loans->execute();
$active_loans = $stmt_loans->fetchAll(PDO::FETCH_ASSOC);

// Hitung total transaksi waserda
$total_waserda_query = "SELECT COUNT(*) as total, COALESCE(SUM(total_bayar), 0) as total_bayar
                        FROM waserda_transaksi 
                        WHERE anggota_id = :id";
$stmt_total_waserda = $db->prepare($total_waserda_query);
$stmt_total_waserda->bindParam(':id', $id);
$stmt_total_waserda->execute();
$total_waserda = $stmt_total_waserda->fetch(PDO::FETCH_ASSOC);

$statusAnggota = strtolower((string)($member['status'] ?? ''));
$statusBadgeClass = $statusAnggota === 'aktif' ? 'bg-success-lt' : ($statusAnggota === 'nonaktif' || $statusAnggota === 'keluar' ? 'bg-danger-lt' : 'bg-secondary-lt');
$inisial = strtoupper(substr((string)$member['nama_lengkap'], 0, 1));
$shuLabel = (($member['kategori_shu'] ?? 'anggota') === 'ketua_kelompok') ? 'Ketua Kelompok' : 'Anggota';
$shuBadge = (($member['kategori_shu'] ?? 'anggota') === 'ketua_kelompok') ? 'bg-primary-lt' : 'bg-secondary-lt';
$aksiCepat = [
    ['href' => 'setoran.php?anggota_id=' . (int)$id, 'icon' => 'ti ti-plus', 'label' => 'Setor', 'tone' => 'success', 'primary' => true],
    ['href' => 'penarikan.php?anggota_id=' . (int)$id, 'icon' => 'ti ti-minus', 'label' => 'Tarik', 'tone' => 'info', 'primary' => false],
    ['href' => 'pengajuan.php?anggota_id=' . (int)$id, 'icon' => 'ti ti-moneybag', 'label' => 'Pinjaman', 'tone' => 'warning', 'primary' => false],
    ['href' => 'pembelian.php?anggota_id=' . (int)$id, 'icon' => 'ti ti-shopping-cart', 'label' => 'Waserda', 'tone' => 'primary', 'primary' => false],
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detail Anggota - <?php echo APP_NAME; ?></title>
    <?php $uiAssetPrefix = '../'; include __DIR__ . '/layout/assets.php'; ?>
</head>
<body>
    <?php include __DIR__ . '/layout/shell_start.php'; ?>
            <div class="ui-member-detail-page">
                <header class="ui-dash-header">
                    <div class="ui-dash-header-icon" aria-hidden="true"><i class="ti ti-user"></i></div>
                    <div class="ui-dash-header-copy">
                        <p class="ui-dash-eyebrow mb-0">Master data</p>
                        <h1 class="ui-dash-title">Detail Anggota</h1>
                        <p class="ui-dash-meta mb-0">
                            <?php echo htmlspecialchars($member['nama_lengkap']); ?>
                            · <?php echo htmlspecialchars($member['kode_anggota']); ?>
                            <span class="badge <?php echo $statusBadgeClass; ?> ms-1"><?php echo htmlspecialchars(ucfirst($member['status'])); ?></span>
                        </p>
                    </div>
                    <div class="ui-dash-header-actions">
                        <a href="anggota.php" class="btn ui-member-detail-btn-back">
                            <i class="ti ti-arrow-left" aria-hidden="true"></i>
                            <span>Kembali</span>
                        </a>
                        <?php if (isAdmin()): ?>
                        <a href="laporan_anggota.php?id=<?php echo (int)$id; ?>" class="btn ui-member-detail-btn-print">
                            <i class="ti ti-printer" aria-hidden="true"></i>
                            <span class="d-none d-sm-inline">Cetak</span>
                        </a>
                        <?php endif; ?>
                    </div>
                </header>

                <?php ui_flash(); ?>

                <div class="ui-stat-grid" data-count="3">
                    <div class="stat-card tone-success">
                        <div class="card-body d-flex justify-content-between align-items-start gap-2">
                            <div class="min-w-0">
                                <p class="mb-0 text-muted small text-uppercase">Total Simpanan</p>
                                <h4 class="mb-0"><?php echo formatRupiah($total_simpanan); ?></h4>
                                <small class="text-muted">Saldo seluruh jenis</small>
                            </div>
                            <span class="stat-icon"><i class="ti ti-pig-money"></i></span>
                        </div>
                    </div>
                    <div class="stat-card tone-warning">
                        <div class="card-body d-flex justify-content-between align-items-start gap-2">
                            <div class="min-w-0">
                                <p class="mb-0 text-muted small text-uppercase">Sisa Pokok</p>
                                <h4 class="mb-0"><?php echo formatRupiah($loan_summary['total_sisa']); ?></h4>
                                <small class="text-muted"><?php echo (int)$loan_summary['total_pinjaman']; ?> pinjaman aktif</small>
                            </div>
                            <span class="stat-icon"><i class="ti ti-moneybag"></i></span>
                        </div>
                    </div>
                    <div class="stat-card tone-info">
                        <div class="card-body d-flex justify-content-between align-items-start gap-2">
                            <div class="min-w-0">
                                <p class="mb-0 text-muted small text-uppercase">Waserda</p>
                                <h4 class="mb-0"><?php echo formatRupiah($total_waserda['total_bayar']); ?></h4>
                                <small class="text-muted"><?php echo (int)$total_waserda['total']; ?> transaksi</small>
                            </div>
                            <span class="stat-icon"><i class="ti ti-building-store"></i></span>
                        </div>
                    </div>
                </div>

                <section class="ui-dash-panel" aria-label="Aksi cepat">
                    <div class="ui-dash-sec">
                        <h3 class="ui-dash-sec-title"><i class="ti ti-bolt" aria-hidden="true"></i>Aksi cepat</h3>
                        <span class="ui-dash-sec-note">Transaksi untuk anggota ini</span>
                    </div>
                    <div class="ui-dash-actions" data-count="<?php echo count($aksiCepat); ?>">
                        <?php foreach ($aksiCepat as $act): ?>
                        <a class="ui-dash-action tone-<?php echo $act['tone']; ?><?php echo !empty($act['primary']) ? ' is-primary' : ''; ?>" href="<?php echo htmlspecialchars($act['href']); ?>">
                            <span class="ui-dash-action-icon"><i class="<?php echo htmlspecialchars($act['icon']); ?>" aria-hidden="true"></i></span>
                            <span class="ui-dash-action-label"><?php echo htmlspecialchars($act['label']); ?></span>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </section>

                <div class="row g-3">
                    <div class="col-lg-4">
                        <div class="card mb-3">
                            <div class="ui-member-profile">
                                <div class="ui-member-profile-avatar" aria-hidden="true"><?php echo htmlspecialchars($inisial); ?></div>
                                <h2 class="ui-member-profile-name"><?php echo htmlspecialchars($member['nama_lengkap']); ?></h2>
                                <div class="ui-member-profile-code">
                                    <span><?php echo htmlspecialchars($member['kode_anggota']); ?></span>
                                    <span class="badge <?php echo $statusBadgeClass; ?>"><?php echo htmlspecialchars(ucfirst($member['status'])); ?></span>
                                    <span class="badge <?php echo $shuBadge; ?>"><?php echo htmlspecialchars($shuLabel); ?></span>
                                </div>
                                <div class="ui-member-profile-contact">
                                    <?php if (!empty($member['telepon'])): ?>
                                        <span class="ui-members-phone"><?php echo htmlspecialchars($member['telepon']); ?></span>
                                        <?php echo tombolWhatsApp($member['telepon'], '', ''); ?>
                                    <?php else: ?>
                                        <span class="text-muted small">Telepon belum diisi</span>
                                    <?php endif; ?>
                                </div>
                                <div class="ui-member-profile-meta">
                                    <span><i class="ti ti-calendar me-1" aria-hidden="true"></i>Bergabung <?php echo formatTanggalUi($member['tanggal_daftar'], false, true); ?></span>
                                    <?php if (!empty($member['username'])): ?>
                                    <span><i class="ti ti-user me-1" aria-hidden="true"></i><?php echo htmlspecialchars($member['username']); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <?php include __DIR__ . '/kartu_dokumen_anggota.php'; ?>
                        
                        <div class="card mb-3">
                            <div class="card-header ui-dash-sec">
                                <span class="ui-dash-sec-title"><i class="ti ti-pig-money" aria-hidden="true"></i>Ringkasan Simpanan</span>
                            </div>
                            <div class="card-body">
                                <div class="ui-member-kv">
                                    <div class="ui-member-kv-row">
                                        <span class="ui-member-kv-label">Pokok</span>
                                        <span class="ui-member-kv-value"><?php echo formatRupiah($simpanan_pokok); ?></span>
                                </div>
                                    <div class="ui-member-kv-row">
                                        <span class="ui-member-kv-label">Wajib</span>
                                        <span class="ui-member-kv-value"><?php echo formatRupiah($simpanan_wajib); ?></span>
                                </div>
                                    <div class="ui-member-kv-row">
                                        <span class="ui-member-kv-label">Sukarela</span>
                                        <span class="ui-member-kv-value"><?php echo formatRupiah($simpanan_sukarela); ?></span>
                                </div>
                                <?php foreach ($simpanan_lainnya as $lainnya): ?>
                                    <div class="ui-member-kv-row">
                                        <span class="ui-member-kv-label"><?php echo htmlspecialchars($lainnya['nama']); ?></span>
                                        <span class="ui-member-kv-value"><?php echo formatRupiah($lainnya['saldo']); ?></span>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="ui-member-kv-total">
                                    <span>Total</span>
                                    <span class="ui-member-kv-value"><?php echo formatRupiah($total_simpanan); ?></span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="card mb-3">
                            <div class="card-header ui-dash-sec">
                                <span class="ui-dash-sec-title"><i class="ti ti-moneybag" aria-hidden="true"></i>Ringkasan Pinjaman</span>
                            </div>
                            <div class="card-body">
                                <div class="ui-member-kv">
                                    <div class="ui-member-kv-row">
                                        <span class="ui-member-kv-label">Aktif</span>
                                        <span class="ui-member-kv-value"><?php echo (int)$loan_summary['total_pinjaman']; ?></span>
                                    </div>
                                    <div class="ui-member-kv-row">
                                        <span class="ui-member-kv-label">Pokok</span>
                                        <span class="ui-member-kv-value"><?php echo formatRupiah($loan_summary['total_jumlah']); ?></span>
                                </div>
                                    <div class="ui-member-kv-row">
                                        <span class="ui-member-kv-label">Bunga</span>
                                        <span class="ui-member-kv-value"><?php echo formatRupiah($loan_summary['total_bunga']); ?></span>
                                </div>
                                    <div class="ui-member-kv-row">
                                        <span class="ui-member-kv-label">Total + bunga</span>
                                        <span class="ui-member-kv-value"><?php echo formatRupiah($total_harus_dibayar); ?></span>
                                </div>
                                    <div class="ui-member-kv-row">
                                        <span class="ui-member-kv-label">Sisa pokok</span>
                                        <span class="ui-member-kv-value text-danger"><?php echo formatRupiah($loan_summary['total_sisa']); ?></span>
                                </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="card mb-3 mb-lg-0">
                            <div class="card-header ui-dash-sec">
                                <span class="ui-dash-sec-title"><i class="ti ti-building-store" aria-hidden="true"></i>Ringkasan Waserda</span>
                            </div>
                            <div class="card-body">
                                <div class="ui-member-kv">
                                    <div class="ui-member-kv-row">
                                        <span class="ui-member-kv-label">Transaksi</span>
                                        <span class="ui-member-kv-value"><?php echo (int)$total_waserda['total']; ?></span>
                                    </div>
                                    <div class="ui-member-kv-row">
                                        <span class="ui-member-kv-label">Total pembelian</span>
                                        <span class="ui-member-kv-value"><?php echo formatRupiah($total_waserda['total_bayar']); ?></span>
                                </div>
                                </div>
                                <a href="pembelian.php?anggota_id=<?php echo (int)$id; ?>" class="btn btn-outline-primary btn-sm w-100 mt-2">
                                    <i class="ti ti-shopping-cart me-1" aria-hidden="true"></i>Buka waserda
                                </a>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-lg-8">
                        <div class="card mb-3">
                            <div class="card-header ui-dash-sec">
                                <span class="ui-dash-sec-title"><i class="ti ti-id" aria-hidden="true"></i>Informasi Pribadi</span>
                            </div>
                            <div class="card-body">
                                <div class="ui-member-info-grid">
                                    <div class="ui-member-info-item">
                                        <span class="ui-member-info-label">Nama lengkap</span>
                                        <div class="ui-member-info-value"><?php echo htmlspecialchars($member['nama_lengkap']); ?></div>
                                    </div>
                                    <div class="ui-member-info-item">
                                        <span class="ui-member-info-label">Jenis kelamin</span>
                                        <div class="ui-member-info-value"><?php echo $member['jenis_kelamin'] == 'L' ? 'Laki-laki' : 'Perempuan'; ?></div>
                                    </div>
                                    <div class="ui-member-info-item">
                                        <span class="ui-member-info-label">Tempat lahir</span>
                                        <div class="ui-member-info-value"><?php echo htmlspecialchars($member['tempat_lahir'] ?: '—'); ?></div>
                                    </div>
                                    <div class="ui-member-info-item">
                                        <span class="ui-member-info-label">Tanggal lahir</span>
                                        <div class="ui-member-info-value"><?php echo $member['tanggal_lahir'] ? formatTanggalUi($member['tanggal_lahir'], false, true) : '—'; ?></div>
                                    </div>
                                    <div class="ui-member-info-item">
                                        <span class="ui-member-info-label">Pekerjaan</span>
                                        <div class="ui-member-info-value"><?php echo htmlspecialchars($member['pekerjaan'] ?: '—'); ?></div>
                                    </div>
                                    <div class="ui-member-info-item">
                                        <span class="ui-member-info-label">Telepon</span>
                                        <div class="ui-member-info-value"><?php echo htmlspecialchars($member['telepon'] ?: '—'); ?></div>
                                    </div>
                                    <div class="ui-member-info-item">
                                        <span class="ui-member-info-label">Email</span>
                                        <div class="ui-member-info-value"><?php echo htmlspecialchars($member['email'] ?: '—'); ?></div>
                                    </div>
                                    <div class="ui-member-info-item">
                                        <span class="ui-member-info-label">Username</span>
                                        <div class="ui-member-info-value"><?php echo htmlspecialchars($member['username'] ?: '—'); ?></div>
                                </div>
                                    <div class="ui-member-info-item is-full">
                                        <span class="ui-member-info-label">Alamat</span>
                                        <div class="ui-member-info-value"><?php echo nl2br(htmlspecialchars($member['alamat'] ?: '—')); ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card mb-3">
                            <div class="card-header ui-dash-sec">
                                <span class="ui-dash-sec-title"><i class="ti ti-arrows-exchange" aria-hidden="true"></i>Transaksi terakhir</span>
                                <span class="ui-dash-sec-note">Tabungan &amp; waserda</span>
                            </div>
                                <?php if (!empty($recent_transactions)): ?>
                                    <div class="list-group list-group-flush">
                                        <?php foreach ($recent_transactions as $transaction): 
                                    $isWaserda = ($transaction['sumber'] ?? '') === 'Waserda';
                                    $isSetoran = !$isWaserda && ($transaction['jenis_transaksi'] ?? '') === 'setoran';
                                    $txClass = $isWaserda ? 'is-waserda' : ($isSetoran ? 'is-in' : 'is-out');
                                    $amtClass = $txClass;
                                    $prefix = $isWaserda || $isSetoran ? '+' : '−';
                                ?>
                                <div class="list-group-item ui-member-tx-item <?php echo $txClass; ?>">
                                    <div class="min-w-0">
                                        <div class="ui-member-tx-code">
                                            <?php echo htmlspecialchars($transaction['kode_transaksi']); ?>
                                            <?php if ($isWaserda): ?>
                                                <span class="badge bg-info-lt ms-1">Waserda</span>
                                                    <?php endif; ?>
                                                </div>
                                        <div class="ui-member-tx-sub">
                                            <?php echo htmlspecialchars($transaction['nama_jenis']); ?>
                                            <?php if ($isWaserda && !empty($transaction['keterangan'])): ?>
                                                · <?php echo htmlspecialchars($transaction['keterangan']); ?>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                    <div class="text-end flex-shrink-0">
                                        <div class="ui-member-tx-amt <?php echo $amtClass; ?>">
                                            <?php echo $prefix; ?> <?php echo formatRupiah($transaction['jumlah']); ?>
                                        </div>
                                        <span class="ui-member-tx-date"><?php echo formatTanggalUi($transaction['tanggal_transaksi'], true, true); ?></span>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php else: ?>
                            <div class="ui-dash-empty"><i class="ti ti-inbox" aria-hidden="true"></i><span>Belum ada transaksi</span></div>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($active_loans)): ?>
                        <div class="card mb-0">
                            <div class="card-header ui-dash-sec">
                                <span class="ui-dash-sec-title"><i class="ti ti-list" aria-hidden="true"></i>Pinjaman aktif</span>
                                <span class="ui-dash-sec-note"><?php echo count($active_loans); ?> kontrak</span>
                            </div>
                            <div class="card-body">
                                <div class="list-group list-group-flush">
                                    <?php foreach ($active_loans as $loan): 
                                        $sisa_bunga = $loan['total_pinjaman'] - $loan['jumlah_pinjaman'];
                                        $total_sisa = $loan['sisa_pokok'] + $sisa_bunga;
                                    ?>
                                    <div class="list-group-item ui-member-loan-item">
                                        <div class="min-w-0">
                                            <div class="ui-member-loan-code"><?php echo htmlspecialchars($loan['kode_pinjaman']); ?></div>
                                            <div class="ui-member-loan-meta">
                                                <?php echo htmlspecialchars($loan['nama_jenis']); ?>
                                                · <?php echo htmlspecialchars(labelBungaRow($loan)); ?>
                                                <br>
                                                Tenor <?php echo htmlspecialchars(labelTenorRow($loan)); ?>
                                                · JT <?php echo formatTanggalUi($loan['jatuh_tempo'], false, true); ?>
                                                · Cair <?php echo formatTanggalUi($loan['tanggal_pencairan'], false, true); ?>
                                            </div>
                                        </div>
                                        <div class="ui-member-loan-figures flex-shrink-0">
                                            <strong class="text-warning"><?php echo formatRupiah($loan['jumlah_pinjaman']); ?></strong>
                                            <span class="text-muted">Bunga <?php echo formatRupiah($sisa_bunga); ?></span><br>
                                            <span class="text-danger fw-semibold">Sisa <?php echo formatRupiah($total_sisa); ?></span>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    </div>
                </div>
            <?php include __DIR__ . '/layout/shell_end.php'; ?>

    <?php include __DIR__ . '/modal_dokumen.php'; ?>
</body>
</html>
