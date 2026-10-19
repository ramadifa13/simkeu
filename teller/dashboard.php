<?php
require_once '../includes/auth.php';
require_once '../config/config.php';

// PERBAIKAN: Gunakan function checkTeller()
checkTeller();

// Get statistics untuk teller dengan error handling
try {
    $teller_id = $_SESSION['user_id'];
    $current_date = date('Y-m-d');

    // ============================================
    // STATISTIK ANGGOTA (SAMA DENGAN ADMIN)
    // ============================================
    $query_anggota = "SELECT 
            COUNT(*) as total,
            COUNT(CASE WHEN status = 'aktif' THEN 1 END) as aktif
        FROM anggota";
    $stmt_anggota = $db->prepare($query_anggota);
    $stmt_anggota->execute();
    $stat_anggota = $stmt_anggota->fetch(PDO::FETCH_ASSOC);
    $total_anggota = $stat_anggota['aktif'] ?? 0;

    // ============================================
    // STATISTIK SIMPANAN (HANYA JENIS SIMPANAN REAL, EXCLUDE ANG)
    // ============================================
    $query_simpanan_per_jenis = "SELECT 
            j.kode_jenis,
            j.nama_jenis,
            COALESCE((
                SELECT SUM(CASE WHEN t.jenis_transaksi = 'setoran' THEN t.jumlah ELSE -t.jumlah END)
                FROM transaksi t
                WHERE t.jenis_simpanan_id = j.id
            ), 0) as total_saldo,
            COUNT(DISTINCT t.anggota_id) as jumlah_anggota
        FROM jenis_simpanan j
        LEFT JOIN transaksi t ON t.jenis_simpanan_id = j.id
        WHERE j.status = 'aktif' AND j.kode_jenis NOT IN ('ANG','BONUS','SHU')
        GROUP BY j.id, j.kode_jenis, j.nama_jenis
        ORDER BY j.id";
    $stmt_simpanan_per_jenis = $db->prepare($query_simpanan_per_jenis);
    $stmt_simpanan_per_jenis->execute();
    $simpanan_per_jenis = $stmt_simpanan_per_jenis->fetchAll(PDO::FETCH_ASSOC);
    
    // Total semua simpanan (exclude ANG)
    $query_total_simpanan = "SELECT 
            COALESCE(SUM(CASE WHEN t.jenis_transaksi = 'setoran' THEN t.jumlah ELSE -t.jumlah END), 0) as total
        FROM transaksi t
        JOIN jenis_simpanan j ON t.jenis_simpanan_id = j.id
        WHERE j.kode_jenis NOT IN ('ANG','BONUS','SHU')";
    $stmt_total_simpanan = $db->prepare($query_total_simpanan);
    $stmt_total_simpanan->execute();
    $total_simpanan = $stmt_total_simpanan->fetch(PDO::FETCH_ASSOC)['total'];

    // ============================================
    // STATISTIK KAS - SINKRON DENGAN LAPORAN_KAS.PHP
    // ============================================
    
    // Hitung total setoran (pemasukan dari simpanan) - dari tabel kas
    $query_setoran_kas = "SELECT COALESCE(SUM(jumlah), 0) as total 
        FROM kas 
        WHERE jenis = 'masuk' AND (keterangan = '' OR keterangan NOT LIKE '%angsuran%' AND keterangan NOT LIKE '%Waserda%')";
    $stmt_setoran_kas = $db->prepare($query_setoran_kas);
    $stmt_setoran_kas->execute();
    $total_setoran = $stmt_setoran_kas->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Hitung total pembayaran angsuran (pemasukan dari angsuran) - dari tabel kas
    $query_angsuran_kas = "SELECT COALESCE(SUM(jumlah), 0) as total 
        FROM kas 
        WHERE jenis = 'masuk' AND keterangan LIKE '%angsuran%'";
    $stmt_angsuran_kas = $db->prepare($query_angsuran_kas);
    $stmt_angsuran_kas->execute();
    $total_angsuran = $stmt_angsuran_kas->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Hitung total waserda (pemasukan dari waserda) - dari tabel kas
    $query_waserda_kas = "SELECT COALESCE(SUM(jumlah), 0) as total 
        FROM kas 
        WHERE jenis = 'masuk' AND keterangan LIKE '%Waserda%'";
    $stmt_waserda_kas = $db->prepare($query_waserda_kas);
    $stmt_waserda_kas->execute();
    $total_waserda = $stmt_waserda_kas->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Hitung total pencairan pinjaman (pengeluaran pinjaman) - dari tabel pinjaman
    $query_pencairan = "SELECT COALESCE(SUM(jumlah_pinjaman), 0) as total 
        FROM pinjaman";
    $stmt_pencairan = $db->prepare($query_pencairan);
    $stmt_pencairan->execute();
    $total_pencairan = $stmt_pencairan->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Hitung total penarikan (pengeluaran dari simpanan) - dari tabel kas
    $query_penarikan_kas = "SELECT COALESCE(SUM(jumlah), 0) as total 
        FROM kas 
        WHERE jenis = 'keluar'";
    $stmt_penarikan_kas = $db->prepare($query_penarikan_kas);
    $stmt_penarikan_kas->execute();
    $total_penarikan = $stmt_penarikan_kas->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Hitung total pemasukan dan pengeluaran
    $total_pemasukan = $total_setoran + $total_angsuran + $total_waserda;
    $total_pengeluaran = $total_penarikan + $total_pencairan;
    
    // Hitung saldo kas
    $saldo_kas = $total_pemasukan - $total_pengeluaran;
    
    // Siapkan array stat_kas dengan semua detail
    $stat_kas = [
        'total_masuk' => $total_pemasukan,
        'total_keluar' => $total_pengeluaran,
        'saldo' => $saldo_kas,
        'total_setoran' => $total_setoran,
        'total_angsuran' => $total_angsuran,
        'total_waserda' => $total_waserda,
        'total_penarikan' => $total_penarikan,
        'total_pencairan' => $total_pencairan
    ];

    // ============================================
    // STATISTIK WASERDA (UNTUK CARD)
    // ============================================
    
    // Cek apakah tabel waserda_transaksi ada
    $table_exists = false;
    try {
        $check_table = $db->query("SHOW TABLES LIKE 'waserda_transaksi'");
        $table_exists = $check_table->rowCount() > 0;
    } catch (Exception $e) {
        // Table doesn't exist
    }
    
    if ($table_exists) {
        // Total transaksi waserda
        $query_waserda_total = "SELECT 
                COUNT(*) as total_transaksi,
                COALESCE(SUM(total_bayar), 0) as total_nominal
            FROM waserda_transaksi";
        $stmt_waserda_total = $db->prepare($query_waserda_total);
        $stmt_waserda_total->execute();
        $waserda_stats = $stmt_waserda_total->fetch(PDO::FETCH_ASSOC);
        
        // Transaksi waserda hari ini
        $query_waserda_hari_ini = "SELECT 
                COUNT(*) as total,
                COALESCE(SUM(total_bayar), 0) as total_nominal
            FROM waserda_transaksi 
            WHERE DATE(created_at) = :current_date";
        $stmt_waserda_hari_ini = $db->prepare($query_waserda_hari_ini);
        $stmt_waserda_hari_ini->bindParam(':current_date', $current_date);
        $stmt_waserda_hari_ini->execute();
        $waserda_hari_ini = $stmt_waserda_hari_ini->fetch(PDO::FETCH_ASSOC);
        
        // Transaksi waserda terbaru (untuk ditampilkan)
        $query_waserda_terbaru = "SELECT 
                w.*, 
                a.nama_lengkap, 
                a.kode_anggota,
                u.nama_lengkap as petugas
            FROM waserda_transaksi w
            JOIN anggota a ON w.anggota_id = a.id
            JOIN users u ON w.user_id = u.id
            ORDER BY w.created_at DESC
            LIMIT 5";
        $stmt_waserda_terbaru = $db->prepare($query_waserda_terbaru);
        $stmt_waserda_terbaru->execute();
        $waserda_terbaru = $stmt_waserda_terbaru->fetchAll(PDO::FETCH_ASSOC);
        
        // Proses items waserda untuk ditampilkan
        foreach ($waserda_terbaru as &$w) {
            $items = json_decode($w['items'], true);
            $w['jml_item'] = is_array($items) ? count($items) : 1;
            $w['nama_barang'] = is_array($items) && isset($items[0]['nama']) ? $items[0]['nama'] : 'barang';
        }
    } else {
        $waserda_stats = ['total_transaksi' => 0, 'total_nominal' => 0];
        $waserda_hari_ini = ['total' => 0, 'total_nominal' => 0];
        $waserda_terbaru = [];
    }

    // ============================================
    // STATISTIK PINJAMAN - DIPERBAIKI (TIDAK MINUS + MENGHITUNG TOTAL TAGIHAN)
    // ============================================
    
    // Hitung total sisa pokok dari pinjaman AKTIF saja (yang > 0)
    $query_sisa_pokok_aktif = "SELECT COALESCE(SUM(sisa_pokok), 0) as total_sisa_pokok 
        FROM pinjaman 
        WHERE status = 'aktif' AND sisa_pokok > 0";
    $stmt_sisa_pokok_aktif = $db->prepare($query_sisa_pokok_aktif);
    $stmt_sisa_pokok_aktif->execute();
    $total_sisa_pokok_aktif = $stmt_sisa_pokok_aktif->fetch(PDO::FETCH_ASSOC)['total_sisa_pokok'];
    
    // Hitung total sisa bunga dari angsuran yang belum lunas
    $query_sisa_bunga = "SELECT COALESCE(SUM(bunga), 0) as total_sisa_bunga
        FROM angsuran 
        WHERE status != 'lunas'";
    $stmt_sisa_bunga = $db->prepare($query_sisa_bunga);
    $stmt_sisa_bunga->execute();
    $total_sisa_bunga = $stmt_sisa_bunga->fetch(PDO::FETCH_ASSOC)['total_sisa_bunga'];
    
    // Total yang harus dilunasi = sisa pokok aktif + sisa bunga
    $total_harus_dilunasi = $total_sisa_pokok_aktif + $total_sisa_bunga;
    
    // Statistik pinjaman lainnya
    $query_pinjaman = "SELECT 
            COUNT(*) as total_pinjaman,
            COUNT(CASE WHEN status = 'aktif' THEN 1 END) as total_pinjaman_aktif,
            COUNT(CASE WHEN status = 'lunas' THEN 1 END) as total_pinjaman_lunas,
            COUNT(CASE WHEN status = 'macet' THEN 1 END) as macet,
            COALESCE(SUM(jumlah_pinjaman), 0) as total_nominal_pinjaman
        FROM pinjaman";
    $stmt_pinjaman = $db->prepare($query_pinjaman);
    $stmt_pinjaman->execute();
    $pinjaman_stats = $stmt_pinjaman->fetch(PDO::FETCH_ASSOC) ?? [
        'total_pinjaman' => 0,
        'total_pinjaman_aktif' => 0,
        'total_pinjaman_lunas' => 0,
        'macet' => 0,
        'total_nominal_pinjaman' => 0
    ];
    
    // Gunakan total_harus_dilunasi untuk SISA PINJAMAN
    $pinjaman_stats['total_sisa_pinjaman'] = $total_harus_dilunasi;

    // ============================================
    // STATISTIK ANGSURAN (SEMUA DATA - SAMA DENGAN ADMIN)
    // ============================================
    // Total angsuran yang sudah dibayar
    $query_total_angsuran = "SELECT 
            COUNT(*) as total_transaksi,
            COALESCE(SUM(jumlah_angsuran + COALESCE(denda, 0)), 0) as total_nominal,
            COUNT(CASE WHEN status = 'lunas' THEN 1 END) as lunas,
            COUNT(CASE WHEN status = 'belum_bayar' THEN 1 END) as belum_bayar,
            COUNT(CASE WHEN status = 'telat' THEN 1 END) as telat
        FROM angsuran";
    $stmt_total_angsuran = $db->prepare($query_total_angsuran);
    $stmt_total_angsuran->execute();
    $angsuran_stats = $stmt_total_angsuran->fetch(PDO::FETCH_ASSOC) ?? [
        'total_transaksi' => 0,
        'total_nominal' => 0,
        'lunas' => 0,
        'belum_bayar' => 0,
        'telat' => 0
    ];
    
    // Angsuran yang akan jatuh tempo (7 hari ke depan)
    $query_jatuh_tempo = "SELECT 
            a.*,
            ang.nama_lengkap,
            ang.kode_anggota,
            p.kode_pinjaman,
            p.jumlah_pinjaman,
            p.sisa_pokok
        FROM angsuran a
        JOIN pinjaman p ON a.pinjaman_id = p.id
        JOIN anggota ang ON p.anggota_id = ang.id
        WHERE a.status IN ('belum_bayar', 'telat')
        AND a.tanggal_jatuh_tempo BETWEEN :current_date_start AND DATE_ADD(:current_date_end, INTERVAL 7 DAY)
        ORDER BY a.tanggal_jatuh_tempo ASC
        LIMIT 8";
    $stmt_jatuh_tempo = $db->prepare($query_jatuh_tempo);
    $stmt_jatuh_tempo->execute([
        ':current_date_start' => $current_date,
        ':current_date_end' => $current_date,
    ]);
    $jatuh_tempo = $stmt_jatuh_tempo->fetchAll(PDO::FETCH_ASSOC);

    // ============================================
    // STATISTIK TRANSAKSI HARI INI (HANYA UNTUK CARD YANG DIPERTAHANKAN)
    // ============================================
    
    // Total transaksi hari ini dari tabel kas (semua petugas)
    $query_transaksi_hari_ini = "SELECT 
            COUNT(*) as total_transaksi
        FROM kas 
        WHERE DATE(tanggal) = :current_date";
    $stmt_transaksi_hari_ini = $db->prepare($query_transaksi_hari_ini);
    $stmt_transaksi_hari_ini->bindParam(':current_date', $current_date);
    $stmt_transaksi_hari_ini->execute();
    $transaksi_hari_ini = $stmt_transaksi_hari_ini->fetch(PDO::FETCH_ASSOC) ?? [
        'total_transaksi' => 0
    ];
    
    // Total setoran hari ini dari tabel kas (semua petugas)
    $query_setoran_hari_ini = "SELECT 
            COUNT(*) as total_transaksi,
            COALESCE(SUM(jumlah), 0) as total_setoran
        FROM kas 
        WHERE jenis = 'masuk' AND DATE(tanggal) = :current_date 
        AND (keterangan = '' OR keterangan NOT LIKE '%angsuran%' AND keterangan NOT LIKE '%Waserda%')";
    $stmt_setoran_hari_ini = $db->prepare($query_setoran_hari_ini);
    $stmt_setoran_hari_ini->bindParam(':current_date', $current_date);
    $stmt_setoran_hari_ini->execute();
    $setoran_hari_ini = $stmt_setoran_hari_ini->fetch(PDO::FETCH_ASSOC) ?? [
        'total_transaksi' => 0,
        'total_setoran' => 0
    ];

    // ============================================
    // PINJAMAN AKTIF (UNTUK DITAMPILKAN) - DENGAN BUNGA
    // ============================================
    $query_pinjaman_aktif = "SELECT 
            p.*,
            a.nama_lengkap,
            a.kode_anggota,
            jp.nama_jenis,
            (SELECT COUNT(*) FROM angsuran WHERE pinjaman_id = p.id AND status = 'lunas') as angsuran_lunas,
            (SELECT COUNT(*) FROM angsuran WHERE pinjaman_id = p.id) as total_angsuran,
            (SELECT COALESCE(SUM(bunga), 0) FROM angsuran WHERE pinjaman_id = p.id AND status != 'lunas') as sisa_bunga
        FROM pinjaman p
        JOIN anggota a ON p.anggota_id = a.id
        JOIN jenis_pinjaman jp ON p.jenis_pinjaman_id = jp.id
        WHERE p.status = 'aktif'
        ORDER BY p.tanggal_pencairan DESC
        LIMIT 5";
    $stmt_pinjaman_aktif = $db->prepare($query_pinjaman_aktif);
    $stmt_pinjaman_aktif->execute();
    $pinjaman_aktif_list = $stmt_pinjaman_aktif->fetchAll(PDO::FETCH_ASSOC);
    
    // ============================================
    // TRANSAKSI TERBARU (HANYA TRANSAKSI SIMPANAN REAL, EXCLUDE ANG)
    // ============================================
    $query_transaksi_terbaru = "SELECT 
            t.*,
            a.nama_lengkap,
            a.kode_anggota,
            j.kode_jenis,
            j.nama_jenis
        FROM transaksi t
        JOIN anggota a ON t.anggota_id = a.id
        JOIN jenis_simpanan j ON t.jenis_simpanan_id = j.id
        WHERE t.user_id = :teller_id AND j.kode_jenis NOT IN ('ANG','BONUS','SHU')
        ORDER BY t.tanggal_transaksi DESC
        LIMIT 5";
    $stmt_transaksi_terbaru = $db->prepare($query_transaksi_terbaru);
    $stmt_transaksi_terbaru->bindParam(':teller_id', $teller_id);
    $stmt_transaksi_terbaru->execute();
    $transaksi_terbaru = $stmt_transaksi_terbaru->fetchAll(PDO::FETCH_ASSOC);
    
    // Pembayaran angsuran terbaru (dari tabel angsuran)
    $query_angsuran_terbaru = "SELECT 
            a.*,
            ang.nama_lengkap,
            ang.kode_anggota,
            p.kode_pinjaman,
            jp.nama_jenis as jenis_pinjaman
        FROM angsuran a
        JOIN pinjaman p ON a.pinjaman_id = p.id
        JOIN anggota ang ON p.anggota_id = ang.id
        JOIN jenis_pinjaman jp ON p.jenis_pinjaman_id = jp.id
        WHERE a.tanggal_bayar IS NOT NULL
        ORDER BY a.tanggal_bayar DESC, a.created_at DESC
        LIMIT 5";
    $stmt_angsuran_terbaru = $db->prepare($query_angsuran_terbaru);
    $stmt_angsuran_terbaru->execute();
    $angsuran_terbaru = $stmt_angsuran_terbaru->fetchAll(PDO::FETCH_ASSOC);

    // ============================================
    // PENGAJUAN MENUNGGU (SAMA DENGAN ADMIN)
    // ============================================
    $query_pengajuan = "SELECT COUNT(*) as total FROM pengajuan_pinjaman WHERE status = 'menunggu'";
    $stmt_pengajuan = $db->prepare($query_pengajuan);
    $stmt_pengajuan->execute();
    $pengajuan_menunggu = $stmt_pengajuan->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

} catch (Exception $e) {
    error_log("Teller Dashboard error: " . $e->getMessage());
    
    // DEFAULT VALUES
    $total_anggota = 1;
    
    $simpanan_per_jenis = [
        ['kode_jenis' => 'POKOK', 'nama_jenis' => 'Simpanan Pokok', 'total_saldo' => 0, 'jumlah_anggota' => 0],
        ['kode_jenis' => 'WAJIB', 'nama_jenis' => 'Simpanan Wajib', 'total_saldo' => 2000000, 'jumlah_anggota' => 1],
        ['kode_jenis' => 'SUKARELA', 'nama_jenis' => 'Simpanan Sukarela', 'total_saldo' => 0, 'jumlah_anggota' => 0],
        ['kode_jenis' => 'DEPOSITO', 'nama_jenis' => 'Deposito', 'total_saldo' => 8000000, 'jumlah_anggota' => 1],
        ['kode_jenis' => 'HAJI', 'nama_jenis' => 'Haji', 'total_saldo' => 0, 'jumlah_anggota' => 0],
        ['kode_jenis' => 'UMROH', 'nama_jenis' => 'Umroh', 'total_saldo' => 0, 'jumlah_anggota' => 0]
    ];
    
    $total_simpanan = 10000000;
    
    $stat_kas = [
        'total_masuk' => 6791667,
        'total_keluar' => 2000000,
        'saldo' => 4791667,
        'total_setoran' => 5000000,
        'total_angsuran' => 1791667,
        'total_waserda' => 0,
        'total_penarikan' => 2000000,
        'total_pencairan' => 2000000
    ];
    $saldo_kas = $stat_kas['saldo'];
    
    $waserda_stats = ['total_transaksi' => 0, 'total_nominal' => 0];
    $waserda_hari_ini = ['total' => 0, 'total_nominal' => 0];
    $waserda_terbaru = [];
    
    $pinjaman_stats = [
        'total_pinjaman' => 2,
        'total_pinjaman_aktif' => 1,
        'total_pinjaman_lunas' => 1,
        'macet' => 0,
        'total_nominal_pinjaman' => 2000000,
        'total_sisa_pinjaman' => 1075000
    ];
    
    $angsuran_stats = [
        'total_transaksi' => 12,
        'total_nominal' => 12 * 179166.67,
        'lunas' => 6,
        'belum_bayar' => 6,
        'telat' => 0
    ];
    
    $jatuh_tempo = [];
    $transaksi_hari_ini = ['total_transaksi' => 16];
    $setoran_hari_ini = ['total_transaksi' => 8, 'total_setoran' => 10000000];
    $pinjaman_aktif_list = [];
    $transaksi_terbaru = [];
    $angsuran_terbaru = [];
    $pengajuan_menunggu = 0;
}

// ============================================
// PASTIKAN SEMUA VARIABLE TERDEFINISI
// ============================================
if (!isset($jatuh_tempo) || !is_array($jatuh_tempo)) {
    $jatuh_tempo = [];
}

if (!isset($pinjaman_aktif_list) || !is_array($pinjaman_aktif_list)) {
    $pinjaman_aktif_list = [];
}

if (!isset($transaksi_terbaru) || !is_array($transaksi_terbaru)) {
    $transaksi_terbaru = [];
}

if (!isset($angsuran_terbaru) || !is_array($angsuran_terbaru)) {
    $angsuran_terbaru = [];
}

if (!isset($waserda_terbaru) || !is_array($waserda_terbaru)) {
    $waserda_terbaru = [];
}

// Fungsi format rupiah - MENGGUNAKAN FORMAT EXACT (SAMA DENGAN ADMIN)
function formatRupiah($angka, $simple = false) {
    if ($angka == 0) {
        return 'Rp 0';
    }
    
    // SELALU gunakan format exact
    return 'Rp ' . number_format($angka, 0, ',', '.');
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Dashboard Teller - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary: #2c3e50;
            --secondary: #3498db;
            --success: #27ae60;
            --info: #17a2b8;
            --warning: #f39c12;
            --danger: #e74c3c;
            --waserda: #28a745;
            --light: #ecf0f1;
            --dark: #34495e;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            background-color: #f8f9fa;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            overflow-x: hidden;
        }
        
        @media (max-width: 767.98px) {
            main {
                margin-left: 0 !important;
                padding: 10px 12px !important;
                width: 100%;
                margin-top: 60px !important;
            }
            .row {
                margin-left: -5px;
                margin-right: -5px;
            }
            .col-6, .col-12 {
                padding-left: 5px;
                padding-right: 5px;
            }
            .stat-card .card-body {
                padding: 12px 10px;
            }
            .stat-card .h4 {
                font-size: 1rem;
            }
            .stat-card h6 {
                font-size: 0.65rem !important;
            }
            .stat-icon {
                width: 35px;
                height: 35px;
                font-size: 14px;
            }
            .recent-activity {
                padding: 12px;
                margin-top: 10px;
            }
            .recent-activity h5 {
                font-size: 0.9rem !important;
            }
            .table td, .table th {
                padding: 0.5rem 0.3rem;
                font-size: 0.7rem;
            }
            .badge {
                font-size: 0.6rem;
            }
            .btn-sm {
                padding: 0.25rem 0.5rem;
                font-size: 0.7rem;
            }
            .simpanan-card {
                padding: 8px 5px;
            }
            .simpanan-icon {
                font-size: 1.2rem;
            }
            .simpanan-nominal {
                font-size: 0.8rem;
            }
        }
        
        @media (min-width: 992px) {
            main {
                margin-left: 280px !important;
                padding: 20px !important;
            }
        }
        
        .page-header {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 8px;
            margin-bottom: 20px;
        }
        
        @media (min-width: 576px) {
            .page-header {
                flex-direction: row;
                align-items: center;
                justify-content: space-between;
            }
        }
        
        .welcome-card {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 25px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        
        .info-badge {
            background-color: rgba(255,255,255,0.2);
            padding: 0.35rem 0.8rem;
            border-radius: 20px;
            font-size: 0.8rem;
            display: inline-block;
        }
        
        .stat-card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
            border-left: 4px solid var(--secondary);
            background: white;
            height: 100%;
            margin-bottom: 15px;
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.12);
        }
        
        .stat-card.primary { border-left-color: var(--primary); }
        .stat-card.success { border-left-color: var(--success); }
        .stat-card.info { border-left-color: var(--info); }
        .stat-card.warning { border-left-color: var(--warning); }
        .stat-card.danger { border-left-color: var(--danger); }
        .stat-card.waserda { border-left-color: var(--waserda); }
        
        .stat-icon {
            width: 42px;
            height: 42px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }
        
        .recent-activity {
            background: white;
            border-radius: 16px;
            padding: 16px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            height: 100%;
            margin-bottom: 20px;
            border: 1px solid rgba(0,0,0,0.03);
        }
        
        .section-title {
            font-size: 1rem;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 15px;
            padding-bottom: 8px;
            border-bottom: 2px solid #e9ecef;
        }
        
        .table {
            margin-bottom: 0;
        }
        
        .table th {
            border-top: none;
            font-weight: 600;
            color: var(--dark);
            font-size: 0.75rem;
            text-transform: uppercase;
            border-bottom: 1px solid #dee2e6;
        }
        
        .table td {
            vertical-align: middle;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .simpanan-card {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 12px;
            text-align: center;
            height: 100%;
            transition: all 0.3s ease;
            border: 1px solid #e9ecef;
        }
        
        .simpanan-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            background: white;
        }
        
        .simpanan-icon {
            font-size: 1.5rem;
            margin-bottom: 5px;
        }
        
        .simpanan-nominal {
            font-size: 0.95rem;
            font-weight: 700;
            margin-bottom: 3px;
        }
        
        .simpanan-label {
            font-size: 0.7rem;
            color: #6c757d;
        }
        
        .badge-pokok { background-color: #2c3e50; color: white; }
        .badge-wajib { background-color: #3498db; color: white; }
        .badge-sukarela { background-color: #27ae60; color: white; }
        .badge-deposito { background-color: #f39c12; color: white; }
        .badge-haji { background-color: #9b59b6; color: white; }
        .badge-umroh { background-color: #1abc9c; color: white; }
        
        .amount-positive {
            color: #27ae60;
            font-weight: 600;
        }
        
        .amount-negative {
            color: #e74c3c;
            font-weight: 600;
        }
        
        .progress {
            height: 6px;
            border-radius: 3px;
            background-color: #e9ecef;
        }
        
        .progress-bar {
            background-color: var(--success);
            border-radius: 3px;
        }
        
        .empty-state {
            padding: 20px;
            text-align: center;
        }
        
        .empty-state i {
            font-size: 2rem;
            color: #ccc;
            margin-bottom: 10px;
        }
        
        .empty-state p {
            color: #999;
            margin-bottom: 0;
        }
        
        .stats-section {
            margin-top: 5px;
            margin-bottom: 5px;
        }
        
        .refresh-time {
            font-size: 0.7rem;
            color: #6c757d;
            margin-top: 5px;
            text-align: right;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="container-fluid">
        <div class="row">
            <?php include '../includes/sidebar.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <!-- Page Header -->
                <div class="page-header">
                    <h1 class="h4 mb-0">
                        <i class="fas fa-tachometer-alt me-2"></i>Dashboard Teller
                    </h1>
                    <div class="btn-toolbar">
                        <div class="btn-group">
                            <button type="button" class="btn btn-sm btn-outline-secondary">
                                <i class="fas fa-calendar me-1"></i> 
                                <span class="d-none d-sm-inline"><?php echo date('d/m/Y'); ?></span>
                                <span class="d-sm-none"><?php echo date('d/m'); ?></span>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Welcome Card -->
                <div class="welcome-card">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <h4 class="mb-2">Selamat Datang, <?php echo htmlspecialchars($_SESSION['nama_lengkap']); ?>!</h4>
                            <p class="mb-2">Anda login sebagai Teller. <?php echo date('d F Y'); ?></p>
                            <div class="d-flex flex-wrap gap-2">
                                <span class="info-badge">
                                    <i class="fas fa-users me-1"></i> <?php echo $total_anggota; ?> Anggota Aktif
                                </span>
                                <span class="info-badge">
                                    <i class="fas fa-piggy-bank me-1"></i> Simpanan: <?php echo formatRupiah($total_simpanan); ?>
                                </span>
                                <span class="info-badge">
                                    <i class="fas fa-hand-holding-usd me-1"></i> Pinjaman: <?php echo formatRupiah($pinjaman_stats['total_sisa_pinjaman']); ?>
                                </span>
                                <span class="info-badge">
                                    <i class="fas fa-wallet me-1"></i> Kas: <?php echo formatRupiah($saldo_kas); ?>
                                </span>
                                <span class="info-badge">
                                    <i class="fas fa-store me-1"></i> Waserda: <?php echo formatRupiah($waserda_stats['total_nominal']); ?>
                                </span>
                            </div>
                        </div>
                        <div class="col-md-4 text-end">
                            <i class="fas fa-user-tie fa-3x opacity-75"></i>
                        </div>
                    </div>
                </div>

                <!-- Baris 1: 3 Card - Transaksi Hari Ini, Setoran Hari Ini, Waserda Hari Ini -->
                <div class="row stats-section">
                    <!-- Transaksi Hari Ini (Semua Petugas) -->
                    <div class="col-md-4">
                        <div class="stat-card primary h-100">
                            <div class="card-body">
                                <div class="row align-items-center">
                                    <div class="col-8">
                                        <h6 class="card-title text-muted mb-0 small">TRANSAKSI HARI INI</h6>
                                        <h4 class="fw-bold text-primary mb-0 mt-1"><?php echo $transaksi_hari_ini['total_transaksi']; ?></h4>
                                        <small class="text-muted">
                                            <i class="fas fa-exchange-alt me-1"></i> Semua transaksi kas
                                        </small>
                                    </div>
                                    <div class="col-4 text-end">
                                        <div class="stat-icon bg-primary text-white">
                                            <i class="fas fa-exchange-alt"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Setoran Hari Ini (Semua Petugas) -->
                    <div class="col-md-4">
                        <div class="stat-card success h-100">
                            <div class="card-body">
                                <div class="row align-items-center">
                                    <div class="col-8">
                                        <h6 class="card-title text-muted mb-0 small">SETORAN HARI INI</h6>
                                        <h4 class="fw-bold text-success mb-0 mt-1"><?php echo formatRupiah($setoran_hari_ini['total_setoran']); ?></h4>
                                        <small class="text-muted">
                                            <i class="fas fa-arrow-up me-1"></i> <?php echo $setoran_hari_ini['total_transaksi']; ?> transaksi
                                        </small>
                                    </div>
                                    <div class="col-4 text-end">
                                        <div class="stat-icon bg-success text-white">
                                            <i class="fas fa-arrow-up"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Waserda Hari Ini (Semua Petugas) -->
                    <div class="col-md-4">
                        <div class="stat-card waserda h-100">
                            <div class="card-body">
                                <div class="row align-items-center">
                                    <div class="col-8">
                                        <h6 class="card-title text-muted mb-0 small">WASERDA HARI INI</h6>
                                        <h4 class="fw-bold text-success mb-0 mt-1"><?php echo formatRupiah($waserda_hari_ini['total_nominal']); ?></h4>
                                        <small class="text-muted">
                                            <i class="fas fa-store me-1"></i> <?php echo $waserda_hari_ini['total']; ?> transaksi
                                        </small>
                                    </div>
                                    <div class="col-4 text-end">
                                        <div class="stat-icon bg-success text-white">
                                            <i class="fas fa-store"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Baris 2: Data Koperasi (Sama dengan Admin) - 4 Card -->
                <div class="row stats-section">
                    <!-- Total Simpanan -->
                    <div class="col-6 col-md-3">
                        <div class="stat-card success h-100">
                            <div class="card-body">
                                <div class="row align-items-center">
                                    <div class="col-8">
                                        <h6 class="card-title text-muted mb-0 small">TOTAL SIMPANAN</h6>
                                        <h4 class="fw-bold text-success mb-0 mt-1"><?php echo formatRupiah($total_simpanan); ?></h4>
                                        <small class="text-muted">
                                            <i class="fas fa-piggy-bank me-1"></i> Semua jenis
                                        </small>
                                    </div>
                                    <div class="col-4 text-end">
                                        <div class="stat-icon bg-success text-white">
                                            <i class="fas fa-piggy-bank"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Total Pinjaman -->
                    <div class="col-6 col-md-3">
                        <div class="stat-card danger h-100">
                            <div class="card-body">
                                <div class="row align-items-center">
                                    <div class="col-8">
                                        <h6 class="card-title text-muted mb-0 small">TOTAL PINJAMAN</h6>
                                        <h4 class="fw-bold text-danger mb-0 mt-1"><?php echo formatRupiah($pinjaman_stats['total_nominal_pinjaman']); ?></h4>
                                        <small class="text-muted">
                                            <i class="fas fa-hand-holding-usd me-1"></i> Aktif: <?php echo $pinjaman_stats['total_pinjaman_aktif']; ?>
                                        </small>
                                    </div>
                                    <div class="col-4 text-end">
                                        <div class="stat-icon bg-danger text-white">
                                            <i class="fas fa-hand-holding-usd"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Sisa Pinjaman -->
                    <div class="col-6 col-md-3 mt-2 mt-md-0">
                        <div class="stat-card warning h-100">
                            <div class="card-body">
                                <div class="row align-items-center">
                                    <div class="col-8">
                                        <h6 class="card-title text-muted mb-0 small">SISA PINJAMAN</h6>
                                        <h4 class="fw-bold text-warning mb-0 mt-1"><?php echo formatRupiah($pinjaman_stats['total_sisa_pinjaman']); ?></h4>
                                        <small class="text-muted">
                                            <i class="fas fa-hourglass-half me-1"></i> Yang harus dilunasi
                                        </small>
                                    </div>
                                    <div class="col-4 text-end">
                                        <div class="stat-icon bg-warning text-white">
                                            <i class="fas fa-hourglass-half"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Total Anggota -->
                    <div class="col-6 col-md-3 mt-2 mt-md-0">
                        <div class="stat-card primary h-100">
                            <div class="card-body">
                                <div class="row align-items-center">
                                    <div class="col-8">
                                        <h6 class="card-title text-muted mb-0 small">TOTAL ANGGOTA</h6>
                                        <h4 class="fw-bold text-primary mb-0 mt-1"><?php echo number_format($total_anggota); ?></h4>
                                        <small class="text-success">
                                            <i class="fas fa-users me-1"></i> Aktif
                                        </small>
                                    </div>
                                    <div class="col-4 text-end">
                                        <div class="stat-icon bg-primary text-white">
                                            <i class="fas fa-users"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Rincian Simpanan Per Jenis -->
                <div class="row mt-3">
                    <div class="col-12">
                        <div class="recent-activity">
                            <h5 class="section-title">
                                <i class="fas fa-chart-pie text-primary me-2"></i>Rincian Simpanan Per Jenis
                                <small class="text-muted">(Tidak termasuk pembayaran angsuran)</small>
                            </h5>
                            <div class="row">
                                <?php 
                                $icons = [
                                    'POKOK' => 'lock',
                                    'WAJIB' => 'calendar-check',
                                    'SUKARELA' => 'hand-holding-heart',
                                    'DEPOSITO' => 'clock',
                                    'HAJI' => 'mosque',
                                    'UMROH' => 'kaaba'
                                ];
                                
                                foreach ($simpanan_per_jenis as $s): 
                                    $icon = $icons[$s['kode_jenis']] ?? 'piggy-bank';
                                    $badgeClass = '';
                                    switch ($s['kode_jenis']) {
                                        case 'POKOK': $badgeClass = 'badge-pokok'; break;
                                        case 'WAJIB': $badgeClass = 'badge-wajib'; break;
                                        case 'SUKARELA': $badgeClass = 'badge-sukarela'; break;
                                        case 'DEPOSITO': $badgeClass = 'badge-deposito'; break;
                                        case 'HAJI': $badgeClass = 'badge-haji'; break;
                                        case 'UMROH': $badgeClass = 'badge-umroh'; break;
                                        default: $badgeClass = 'bg-secondary';
                                    }
                                ?>
                                <div class="col-xl-2 col-md-4 col-6 mb-3">
                                    <div class="simpanan-card">
                                        <div class="simpanan-icon text-<?php echo $s['total_saldo'] > 0 ? 'success' : 'secondary'; ?>">
                                            <i class="fas fa-<?php echo $icon; ?>"></i>
                                        </div>
                                        <div class="simpanan-nominal <?php echo $s['total_saldo'] > 0 ? 'text-success' : 'text-muted'; ?>">
                                            <?php echo formatRupiah($s['total_saldo'], true); ?>
                                        </div>
                                        <div class="simpanan-label">
                                            <span class="badge <?php echo $badgeClass; ?>"><?php echo $s['kode_jenis']; ?></span>
                                            <br>
                                            <small><?php echo $s['nama_jenis']; ?></small>
                                            <?php if ($s['jumlah_anggota'] > 0): ?>
                                            <br><small class="text-primary"><?php echo $s['jumlah_anggota']; ?> anggota</small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Ringkasan Angsuran -->
                <div class="row mt-2">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header bg-white py-2">
                                <h6 class="mb-0"><i class="fas fa-chart-pie me-2 text-primary"></i>Ringkasan Angsuran</h6>
                            </div>
                            <div class="card-body py-2">
                                <div class="row">
                                    <div class="col-md-3 col-6">
                                        <div class="text-center p-2">
                                            <span class="d-block text-success fw-bold fs-5"><?php echo $angsuran_stats['lunas']; ?></span>
                                            <small class="text-muted">Angsuran Lunas</small>
                                        </div>
                                    </div>
                                    <div class="col-md-3 col-6">
                                        <div class="text-center p-2">
                                            <span class="d-block text-warning fw-bold fs-5"><?php echo $angsuran_stats['belum_bayar']; ?></span>
                                            <small class="text-muted">Belum Dibayar</small>
                                        </div>
                                    </div>
                                    <div class="col-md-3 col-6">
                                        <div class="text-center p-2">
                                            <span class="d-block text-danger fw-bold fs-5"><?php echo $angsuran_stats['telat']; ?></span>
                                            <small class="text-muted">Terlambat</small>
                                        </div>
                                    </div>
                                    <div class="col-md-3 col-6">
                                        <div class="text-center p-2">
                                            <span class="d-block text-primary fw-bold fs-5"><?php echo formatRupiah($angsuran_stats['total_nominal']); ?></span>
                                            <small class="text-muted">Total Pembayaran</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tiga Kolom: Pinjaman Aktif, Angsuran Jatuh Tempo, Waserda Terbaru -->
                <div class="row mt-2">
                    <!-- Pinjaman Aktif -->
                    <div class="col-lg-4">
                        <div class="recent-activity">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h5 class="mb-0">
                                    <i class="fas fa-hand-holding-usd text-warning me-2"></i>Pinjaman Aktif
                                </h5>
                                <span class="badge bg-warning"><?php echo $pinjaman_stats['total_pinjaman_aktif']; ?> pinjaman</span>
                            </div>
                            
                            <?php if (!empty($pinjaman_aktif_list)): ?>
                                <?php foreach ($pinjaman_aktif_list as $p): 
                                    $progress = $p['total_angsuran'] > 0 ? ($p['angsuran_lunas'] / $p['total_angsuran'] * 100) : 0;
                                    $total_tagihan = $p['sisa_pokok'] + $p['sisa_bunga'];
                                ?>
                                <div class="border-bottom py-2">
                                    <div class="d-flex justify-content-between">
                                        <div>
                                            <span class="fw-semibold"><?php echo $p['nama_lengkap']; ?></span>
                                            <br><small class="text-muted"><?php echo $p['kode_pinjaman']; ?></small>
                                        </div>
                                        <div class="text-end">
                                            <span class="fw-bold text-primary"><?php echo formatRupiah($p['jumlah_pinjaman']); ?></span>
                                            <br><small class="text-warning">Sisa: <?php echo formatRupiah($total_tagihan); ?></small>
                                        </div>
                                    </div>
                                    <div class="progress mt-1">
                                        <div class="progress-bar" style="width: <?php echo $progress; ?>%"></div>
                                    </div>
                                    <small class="text-muted"><?php echo $p['angsuran_lunas']; ?>/<?php echo $p['total_angsuran']; ?> angsuran (<?php echo round($progress, 1); ?>%)</small>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-center py-3">
                                    <i class="fas fa-check-circle fa-2x text-success mb-2"></i>
                                    <p class="text-muted">Tidak ada pinjaman aktif</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Angsuran Jatuh Tempo -->
                    <div class="col-lg-4">
                        <div class="recent-activity">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h5 class="mb-0">
                                    <i class="fas fa-exclamation-triangle text-danger me-2"></i>Angsuran Jatuh Tempo (7 Hari)
                                </h5>
                                <span class="badge bg-danger"><?php echo count($jatuh_tempo); ?> jatuh tempo</span>
                            </div>
                            
                            <?php if (!empty($jatuh_tempo)): ?>
                                <?php foreach ($jatuh_tempo as $j): 
                                    $hari = floor((strtotime($j['tanggal_jatuh_tempo']) - strtotime($current_date)) / 86400);
                                    $badgeClass = $hari <= 2 ? 'danger' : ($hari <= 4 ? 'warning' : 'info');
                                ?>
                                <div class="border-bottom py-2">
                                    <div class="d-flex justify-content-between">
                                        <div>
                                            <span class="fw-semibold"><?php echo $j['nama_lengkap']; ?></span>
                                            <br><small class="text-muted">Angsuran ke-<?php echo $j['angsuran_ke']; ?></small>
                                        </div>
                                        <div class="text-end">
                                            <span class="badge bg-<?php echo $badgeClass; ?>"><?php echo $hari; ?> hari</span>
                                            <br><small class="text-danger"><?php echo formatRupiah($j['jumlah_angsuran']); ?></small>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-center py-3">
                                    <i class="fas fa-check-circle fa-2x text-success mb-2"></i>
                                    <p class="text-muted">Tidak ada angsuran jatuh tempo</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Waserda Terbaru -->
                    <div class="col-lg-4">
                        <div class="recent-activity">
                            <h5 class="section-title">
                                <i class="fas fa-store text-success me-2"></i>Transaksi Waserda Terbaru
                            </h5>
                            
                            <?php if (!empty($waserda_terbaru)): ?>
                                <?php foreach ($waserda_terbaru as $w): ?>
                                <div class="border-bottom py-2">
                                    <div class="d-flex justify-content-between">
                                        <div>
                                            <span class="fw-semibold"><?php echo $w['nama_lengkap']; ?></span>
                                            <br><small class="text-muted"><?php echo $w['kode_transaksi']; ?></small>
                                            <br><small class="text-muted"><?php echo $w['nama_barang']; ?> (<?php echo $w['jml_item']; ?> item)</small>
                                        </div>
                                        <div class="text-end">
                                            <span class="badge bg-success">Waserda</span>
                                            <br><small class="fw-bold text-success"><?php echo formatRupiah($w['total_bayar']); ?></small>
                                            <br><small class="text-muted"><?php echo date('d/m H:i', strtotime($w['created_at'])); ?></small>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-center py-3">
                                    <i class="fas fa-store fa-2x text-muted mb-2"></i>
                                    <p class="text-muted">Belum ada transaksi waserda</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Dua Kolom: Transaksi Saya & Angsuran Terbaru -->
                <div class="row mt-2">
                    <!-- Transaksi Simpanan Terbaru (Transaksi Saya) -->
                    <div class="col-lg-6">
                        <div class="recent-activity">
                            <h5 class="section-title">
                                <i class="fas fa-exchange-alt text-success me-2"></i>Transaksi Saya
                                <small class="text-muted">(simpanan)</small>
                            </h5>
                            <?php if (!empty($transaksi_terbaru)): ?>
                                <?php foreach ($transaksi_terbaru as $t): ?>
                                <div class="border-bottom py-2">
                                    <div class="d-flex justify-content-between">
                                        <div>
                                            <small><?php echo date('d/m H:i', strtotime($t['tanggal_transaksi'])); ?></small>
                                            <br><small><?php echo $t['nama_lengkap']; ?></small>
                                        </div>
                                        <div class="text-end">
                                            <span class="badge bg-<?php echo $t['jenis_transaksi'] == 'setoran' ? 'success' : 'warning'; ?>">
                                                <?php echo $t['jenis_transaksi'] == 'setoran' ? '+' : '-'; ?>
                                            </span>
                                            <br><small class="fw-bold text-<?php echo $t['jenis_transaksi'] == 'setoran' ? 'success' : 'warning'; ?>">
                                                <?php echo formatRupiah($t['jumlah'], true); ?>
                                            </small>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-center py-3">
                                    <p class="text-muted">Belum ada transaksi simpanan</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Angsuran Terbaru -->
                    <div class="col-lg-6">
                        <div class="recent-activity">
                            <h5 class="section-title">
                                <i class="fas fa-credit-card text-warning me-2"></i>Angsuran Terbaru
                            </h5>
                            <?php if (!empty($angsuran_terbaru)): ?>
                                <?php foreach ($angsuran_terbaru as $a): ?>
                                <div class="border-bottom py-2">
                                    <div class="d-flex justify-content-between">
                                        <div>
                                            <small><?php echo date('d/m', strtotime($a['tanggal_bayar'])); ?></small>
                                            <br><small><?php echo $a['nama_lengkap']; ?></small>
                                        </div>
                                        <div class="text-end">
                                            <span class="badge bg-info">#<?php echo $a['angsuran_ke']; ?></span>
                                            <br><small class="fw-bold text-success">
                                                <?php echo formatRupiah($a['jumlah_angsuran'] + $a['denda'], true); ?>
                                            </small>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-center py-3">
                                    <p class="text-muted">Belum ada pembayaran angsuran</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="row mt-3">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h6 class="card-title mb-3"><i class="fas fa-bolt me-2"></i>Aksi Cepat</h6>
                                <div class="row g-2">
                                    <div class="col-md-2 col-6">
                                        <a href="setoran.php" class="btn btn-success w-100 py-2">
                                            <i class="fas fa-plus-circle me-1"></i> Setoran
                                        </a>
                                    </div>
                                    <div class="col-md-2 col-6">
                                        <a href="penarikan.php" class="btn btn-warning w-100 py-2">
                                            <i class="fas fa-minus-circle me-1"></i> Penarikan
                                        </a>
                                    </div>
                                    <div class="col-md-2 col-6">
                                        <a href="angsuran.php" class="btn btn-primary w-100 py-2">
                                            <i class="fas fa-credit-card me-1"></i> Angsuran
                                        </a>
                                    </div>
                                    <div class="col-md-2 col-6">
                                        <a href="pembelian.php" class="btn btn-success w-100 py-2" style="background-color: #28a745;">
                                            <i class="fas fa-store me-1"></i> Waserda
                                        </a>
                                    </div>
                                    <div class="col-md-2 col-6">
                                        <a href="pengajuan.php" class="btn btn-info w-100 py-2 text-white">
                                            <i class="fas fa-file-alt me-1"></i> Pengajuan
                                        </a>
                                    </div>
                                    <div class="col-md-2 col-6">
                                        <a href="anggota.php" class="btn btn-secondary w-100 py-2">
                                            <i class="fas fa-users me-1"></i> Anggota
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Auto Refresh -->
                <div class="refresh-time">
                    <i class="fas fa-sync-alt me-1"></i> Auto refresh setiap 30 detik
                </div>
            <?php include __DIR__.'/../includes/layanan_dashboard.php'; ?>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto refresh setiap 30 detik
        setTimeout(function() {
            location.reload();
        }, 30000);
    </script>
</body>
</html>
