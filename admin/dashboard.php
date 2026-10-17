<?php
require_once '../includes/auth.php';
require_once '../config/config.php';

// Cek akses admin
if (!isAdmin()) {
    $_SESSION['error'] = "Anda tidak memiliki akses ke halaman ini";
    header("Location: ../login.php");
    exit();
}

// Get statistics dengan error handling
try {
    $current_date = date('Y-m-d');
    
    // Format tanggal untuk tampilan
    $bulan_indonesia = [
        'January' => 'Januari',
        'February' => 'Februari',
        'March' => 'Maret',
        'April' => 'April',
        'May' => 'Mei',
        'June' => 'Juni',
        'July' => 'Juli',
        'August' => 'Agustus',
        'September' => 'September',
        'October' => 'Oktober',
        'November' => 'November',
        'December' => 'Desember'
    ];
    
    $tanggal_sekarang = date('d') . ' ' . $bulan_indonesia[date('F')] . ' ' . date('Y');
    
    // ============================================
    // STATISTIK ANGGOTA
    // ============================================
    $query_anggota = "SELECT 
            COUNT(*) as total,
            COUNT(CASE WHEN status = 'aktif' THEN 1 END) as aktif,
            COUNT(CASE WHEN status = 'nonaktif' THEN 1 END) as nonaktif,
            COUNT(CASE WHEN DATE(tanggal_daftar) = CURDATE() THEN 1 END) as daftar_hari_ini
        FROM anggota";
    $stmt_anggota = $db->prepare($query_anggota);
    $stmt_anggota->execute();
    $stat_anggota = $stmt_anggota->fetch(PDO::FETCH_ASSOC);
    
    if (!$stat_anggota) {
        $stat_anggota = ['total' => 0, 'aktif' => 0, 'nonaktif' => 0, 'daftar_hari_ini' => 0];
    }

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
        WHERE j.status = 'aktif' AND j.kode_jenis != 'ANG'
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
        WHERE j.kode_jenis != 'ANG'";
    $stmt_total_simpanan = $db->prepare($query_total_simpanan);
    $stmt_total_simpanan->execute();
    $total_simpanan = $stmt_total_simpanan->fetch(PDO::FETCH_ASSOC)['total'];

    // ============================================
    // STATISTIK KAS - SINKRON DENGAN LAPORAN_KAS.PHP
    // ============================================
    
    // 1. Hitung total setoran (pemasukan dari simpanan) - dari tabel kas
    $query_setoran_kas = "SELECT COALESCE(SUM(jumlah), 0) as total 
        FROM kas 
        WHERE jenis = 'masuk' AND (keterangan = '' OR keterangan NOT LIKE '%angsuran%' AND keterangan NOT LIKE '%Waserda%')";
    $stmt_setoran_kas = $db->prepare($query_setoran_kas);
    $stmt_setoran_kas->execute();
    $total_setoran = $stmt_setoran_kas->fetch(PDO::FETCH_ASSOC)['total'];
    
    // 2. Hitung total pembayaran angsuran (pemasukan dari angsuran) - dari tabel kas
    $query_angsuran_kas = "SELECT COALESCE(SUM(jumlah), 0) as total 
        FROM kas 
        WHERE jenis = 'masuk' AND keterangan LIKE '%angsuran%'";
    $stmt_angsuran_kas = $db->prepare($query_angsuran_kas);
    $stmt_angsuran_kas->execute();
    $total_angsuran = $stmt_angsuran_kas->fetch(PDO::FETCH_ASSOC)['total'];
    
    // 3. Hitung total waserda (pemasukan dari waserda) - dari tabel kas
    $query_waserda_kas = "SELECT COALESCE(SUM(jumlah), 0) as total 
        FROM kas 
        WHERE jenis = 'masuk' AND keterangan LIKE '%Waserda%'";
    $stmt_waserda_kas = $db->prepare($query_waserda_kas);
    $stmt_waserda_kas->execute();
    $total_waserda = $stmt_waserda_kas->fetch(PDO::FETCH_ASSOC)['total'];
    
    // 4. Hitung total pencairan pinjaman (pengeluaran pinjaman) - dari tabel pinjaman
    $query_pencairan = "SELECT COALESCE(SUM(jumlah_pinjaman), 0) as total 
        FROM pinjaman";
    $stmt_pencairan = $db->prepare($query_pencairan);
    $stmt_pencairan->execute();
    $total_pencairan = $stmt_pencairan->fetch(PDO::FETCH_ASSOC)['total'];
    
    // 5. Hitung total penarikan (pengeluaran dari simpanan) - dari tabel kas
    $query_penarikan_kas = "SELECT COALESCE(SUM(jumlah), 0) as total 
        FROM kas 
        WHERE jenis = 'keluar'";
    $stmt_penarikan_kas = $db->prepare($query_penarikan_kas);
    $stmt_penarikan_kas->execute();
    $total_penarikan = $stmt_penarikan_kas->fetch(PDO::FETCH_ASSOC)['total'];
    
    // 6. Hitung total pemasukan dan pengeluaran
    $total_pemasukan = $total_setoran + $total_angsuran + $total_waserda;
    $total_pengeluaran = $total_penarikan + $total_pencairan;
    
    // 7. Hitung saldo kas
    $saldo_kas = $total_pemasukan - $total_pengeluaran;
    
    // 8. Siapkan array stat_kas dengan semua detail
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
    // STATISTIK WASERDA
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
        
        // Transaksi waserda terbaru
        $query_waserda_terbaru = "SELECT w.*, a.nama_lengkap, a.kode_anggota, u.nama_lengkap as petugas
            FROM waserda_transaksi w
            JOIN anggota a ON w.anggota_id = a.id
            JOIN users u ON w.user_id = u.id
            ORDER BY w.created_at DESC
            LIMIT 5";
        $stmt_waserda_terbaru = $db->prepare($query_waserda_terbaru);
        $stmt_waserda_terbaru->execute();
        $waserda_terbaru = $stmt_waserda_terbaru->fetchAll(PDO::FETCH_ASSOC);
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
            COUNT(CASE WHEN status = 'aktif' THEN 1 END) as aktif,
            COUNT(CASE WHEN status = 'lunas' THEN 1 END) as lunas,
            COUNT(CASE WHEN status = 'macet' THEN 1 END) as macet,
            COALESCE(SUM(jumlah_pinjaman), 0) as total_nominal
        FROM pinjaman";
    $stmt_pinjaman = $db->prepare($query_pinjaman);
    $stmt_pinjaman->execute();
    $pinjaman_stats = $stmt_pinjaman->fetch(PDO::FETCH_ASSOC);
    
    if (!$pinjaman_stats) {
        $pinjaman_stats = [
            'total_pinjaman' => 0,
            'aktif' => 0,
            'lunas' => 0,
            'macet' => 0,
            'total_nominal' => 0
        ];
    }
    
    // Gunakan total_harus_dilunasi untuk SISA PINJAMAN
    $pinjaman_stats['total_sisa_pokok'] = $total_harus_dilunasi;
    
    // ============================================
    // STATISTIK ANGSURAN
    // ============================================
    $query_angsuran = "SELECT 
            COUNT(*) as total_transaksi,
            COALESCE(SUM(jumlah_angsuran + COALESCE(denda, 0)), 0) as total_nominal,
            COUNT(CASE WHEN status = 'lunas' THEN 1 END) as lunas,
            COUNT(CASE WHEN status = 'belum_bayar' THEN 1 END) as belum_bayar,
            COUNT(CASE WHEN status = 'telat' THEN 1 END) as telat
        FROM angsuran";
    $stmt_angsuran = $db->prepare($query_angsuran);
    $stmt_angsuran->execute();
    $angsuran_stats = $stmt_angsuran->fetch(PDO::FETCH_ASSOC);
    
    if (!$angsuran_stats) {
        $angsuran_stats = [
            'total_transaksi' => 0,
            'total_nominal' => 0,
            'lunas' => 0,
            'belum_bayar' => 0,
            'telat' => 0
        ];
    }
    
    // Angsuran hari ini
    $query_angsuran_hari_ini = "SELECT 
            COUNT(*) as total,
            COALESCE(SUM(jumlah_angsuran + COALESCE(denda, 0)), 0) as total_nominal
        FROM angsuran 
        WHERE DATE(tanggal_bayar) = :current_date AND status = 'lunas'";
    $stmt_angsuran_hari_ini = $db->prepare($query_angsuran_hari_ini);
    $stmt_angsuran_hari_ini->bindParam(':current_date', $current_date);
    $stmt_angsuran_hari_ini->execute();
    $angsuran_hari_ini = $stmt_angsuran_hari_ini->fetch(PDO::FETCH_ASSOC);
    
    if (!$angsuran_hari_ini) {
        $angsuran_hari_ini = ['total' => 0, 'total_nominal' => 0];
    }
    
    // ============================================
    // ANGSURAN YANG AKAN JATUH TEMPO (7 HARI KE DEPAN)
    // ============================================
    $query_jatuh_tempo = "SELECT 
            a.*,
            ang.nama_lengkap,
            ang.kode_anggota,
            p.kode_pinjaman,
            p.jumlah_pinjaman
        FROM angsuran a
        JOIN pinjaman p ON a.pinjaman_id = p.id
        JOIN anggota ang ON p.anggota_id = ang.id
        WHERE a.status IN ('belum_bayar', 'telat')
        AND a.tanggal_jatuh_tempo BETWEEN :current_date AND DATE_ADD(:current_date, INTERVAL 7 DAY)
        ORDER BY a.tanggal_jatuh_tempo ASC
        LIMIT 10";
    $stmt_jatuh_tempo = $db->prepare($query_jatuh_tempo);
    $stmt_jatuh_tempo->bindParam(':current_date', $current_date);
    $stmt_jatuh_tempo->execute();
    $jatuh_tempo = $stmt_jatuh_tempo->fetchAll(PDO::FETCH_ASSOC);

    // ============================================
    // STATISTIK PENGAJUAN
    // ============================================
    $query_pengajuan = "SELECT 
            COUNT(*) as total,
            COUNT(CASE WHEN status = 'menunggu' THEN 1 END) as menunggu,
            COUNT(CASE WHEN status = 'disetujui' THEN 1 END) as disetujui,
            COUNT(CASE WHEN status = 'ditolak' THEN 1 END) as ditolak
        FROM pengajuan_pinjaman";
    $stmt_pengajuan = $db->prepare($query_pengajuan);
    $stmt_pengajuan->execute();
    $pengajuan_stats = $stmt_pengajuan->fetch(PDO::FETCH_ASSOC);
    
    if (!$pengajuan_stats) {
        $pengajuan_stats = ['total' => 0, 'menunggu' => 0, 'disetujui' => 0, 'ditolak' => 0];
    }

    // ============================================
    // PINJAMAN AKTIF (UNTUK DITAMPILKAN)
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
            j.nama_jenis,
            u.nama_lengkap as teller
        FROM transaksi t
        JOIN anggota a ON t.anggota_id = a.id
        JOIN jenis_simpanan j ON t.jenis_simpanan_id = j.id
        JOIN users u ON t.user_id = u.id
        WHERE j.kode_jenis != 'ANG'
        ORDER BY t.tanggal_transaksi DESC
        LIMIT 8";
    $stmt_transaksi_terbaru = $db->prepare($query_transaksi_terbaru);
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
        LIMIT 8";
    $stmt_angsuran_terbaru = $db->prepare($query_angsuran_terbaru);
    $stmt_angsuran_terbaru->execute();
    $angsuran_terbaru = $stmt_angsuran_terbaru->fetchAll(PDO::FETCH_ASSOC);
    
    // Pengajuan terbaru
    $query_pengajuan_terbaru = "SELECT 
            pp.*,
            a.nama_lengkap,
            a.kode_anggota,
            jp.nama_jenis as jenis_pinjaman
        FROM pengajuan_pinjaman pp
        JOIN anggota a ON pp.anggota_id = a.id
        JOIN jenis_pinjaman jp ON pp.jenis_pinjaman_id = jp.id
        WHERE pp.status = 'menunggu'
        ORDER BY pp.tanggal_pengajuan DESC
        LIMIT 5";
    $stmt_pengajuan_terbaru = $db->prepare($query_pengajuan_terbaru);
    $stmt_pengajuan_terbaru->execute();
    $pengajuan_terbaru = $stmt_pengajuan_terbaru->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    error_log("Admin Dashboard error: " . $e->getMessage());
    
    // DEFAULT VALUES
    $stat_anggota = ['total' => 0, 'aktif' => 0, 'nonaktif' => 0, 'daftar_hari_ini' => 0];
    
    // Ambil data jenis simpanan dari database (struktur saja)
    try {
        $query_jenis = "SELECT kode_jenis, nama_jenis FROM jenis_simpanan WHERE status = 'aktif' AND kode_jenis != 'ANG'";
        $stmt_jenis = $db->query($query_jenis);
        $jenis_list = $stmt_jenis->fetchAll(PDO::FETCH_ASSOC);
        
        $simpanan_per_jenis = [];
        foreach ($jenis_list as $j) {
            $simpanan_per_jenis[] = [
                'kode_jenis' => $j['kode_jenis'],
                'nama_jenis' => $j['nama_jenis'],
                'total_saldo' => 0,
                'jumlah_anggota' => 0
            ];
        }
    } catch (Exception $ex) {
        // Fallback ke array default
        $simpanan_per_jenis = [
            ['kode_jenis' => 'POKOK', 'nama_jenis' => 'Simpanan Pokok', 'total_saldo' => 0, 'jumlah_anggota' => 0],
            ['kode_jenis' => 'WAJIB', 'nama_jenis' => 'Simpanan Wajib', 'total_saldo' => 0, 'jumlah_anggota' => 0],
            ['kode_jenis' => 'SUKARELA', 'nama_jenis' => 'Simpanan Sukarela', 'total_saldo' => 0, 'jumlah_anggota' => 0],
            ['kode_jenis' => 'DEPOSITO', 'nama_jenis' => 'Deposito', 'total_saldo' => 0, 'jumlah_anggota' => 0],
            ['kode_jenis' => 'HAJI', 'nama_jenis' => 'Haji', 'total_saldo' => 0, 'jumlah_anggota' => 0],
            ['kode_jenis' => 'UMROH', 'nama_jenis' => 'Umroh', 'total_saldo' => 0, 'jumlah_anggota' => 0]
        ];
    }
    
    $total_simpanan = 0;
    
    $stat_kas = [
        'total_masuk' => 0,
        'total_keluar' => 0,
        'saldo' => 0,
        'total_setoran' => 0,
        'total_angsuran' => 0,
        'total_waserda' => 0,
        'total_penarikan' => 0,
        'total_pencairan' => 0
    ];
    
    $waserda_stats = ['total_transaksi' => 0, 'total_nominal' => 0];
    $waserda_hari_ini = ['total' => 0, 'total_nominal' => 0];
    $waserda_terbaru = [];
    
    $pinjaman_stats = [
        'total_pinjaman' => 0,
        'aktif' => 0,
        'lunas' => 0,
        'macet' => 0,
        'total_nominal' => 0,
        'total_sisa_pokok' => 0
    ];
    
    $angsuran_stats = [
        'total_transaksi' => 0,
        'total_nominal' => 0,
        'lunas' => 0,
        'belum_bayar' => 0,
        'telat' => 0
    ];
    
    $angsuran_hari_ini = ['total' => 0, 'total_nominal' => 0];
    $pengajuan_stats = ['total' => 0, 'menunggu' => 0, 'disetujui' => 0, 'ditolak' => 0];
    $pinjaman_aktif_list = [];
    $transaksi_terbaru = [];
    $angsuran_terbaru = [];
    $pengajuan_terbaru = [];
    $tanggal_sekarang = date('d') . ' ' . ($bulan_indonesia[date('F')] ?? date('F')) . ' ' . date('Y');
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

if (!isset($pengajuan_terbaru) || !is_array($pengajuan_terbaru)) {
    $pengajuan_terbaru = [];
}

if (!isset($waserda_terbaru) || !is_array($waserda_terbaru)) {
    $waserda_terbaru = [];
}

// Fungsi format rupiah - MENGGUNAKAN FORMAT EXACT
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
    <title>Admin Dashboard - <?php echo APP_NAME; ?></title>
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
                font-size: 1.1rem;
            }
            .stat-card h6 {
                font-size: 0.7rem !important;
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
                font-size: 0.75rem;
            }
            .badge {
                font-size: 0.6rem;
            }
            .btn-sm {
                padding: 0.25rem 0.5rem;
                font-size: 0.7rem;
            }
        }
        
        @media (min-width: 992px) {
            main {
                margin-left: 280px !important;
                padding: 20px !important;
            }
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
            transform: translateY(-2px);
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
            font-size: 0.8rem;
            text-transform: uppercase;
            border-bottom: 1px solid #dee2e6;
        }
        
        .table td {
            font-size: 0.8rem;
            vertical-align: middle;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .table tr:last-child td {
            border-bottom: none;
        }
        
        .badge-transaction {
            padding: 0.3rem 0.6rem;
            border-radius: 20px;
            font-weight: 500;
            font-size: 0.7rem;
            display: inline-block;
        }
        
        .amount-positive {
            color: #27ae60;
            font-weight: 600;
        }
        
        .amount-negative {
            color: #e74c3c;
            font-weight: 600;
        }
        
        .page-header {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 8px;
            margin-bottom: 15px;
        }
        
        @media (min-width: 576px) {
            .page-header {
                flex-direction: row;
                align-items: center;
                justify-content: space-between;
            }
        }
        
        .progress {
            height: 8px;
            border-radius: 4px;
            background-color: #e9ecef;
        }
        
        .progress-bar {
            background-color: var(--success);
            border-radius: 4px;
        }
        
        .empty-state {
            padding: 30px 15px;
            text-align: center;
        }
        
        .empty-state i {
            font-size: 3rem;
            color: #ccc;
            margin-bottom: 15px;
        }
        
        .empty-state p {
            color: #999;
            margin-bottom: 15px;
        }
        
        .stats-section {
            margin-top: 5px;
            margin-bottom: 5px;
        }
        
        .badge-pokok { background-color: #2c3e50; color: white; }
        .badge-wajib { background-color: #3498db; color: white; }
        .badge-sukarela { background-color: #27ae60; color: white; }
        .badge-deposito { background-color: #f39c12; color: white; }
        .badge-haji { background-color: #9b59b6; color: white; }
        .badge-umroh { background-color: #1abc9c; color: white; }
        .badge-waserda { background-color: #28a745; color: white; }
        
        .refresh-time {
            font-size: 0.7rem;
            color: #6c757d;
            margin-top: 5px;
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
                        <i class="fas fa-tachometer-alt me-2"></i>Dashboard Admin
                    </h1>
                    <div class="btn-toolbar">
                        <div class="btn-group">
                            <button type="button" class="btn btn-sm btn-outline-secondary">
                                <i class="fas fa-calendar me-1"></i> 
                                <span class="d-none d-sm-inline"><?php echo $tanggal_sekarang; ?></span>
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
                            <p class="mb-2">Anda login sebagai Administrator. <?php echo $tanggal_sekarang; ?></p>
                            <div class="d-flex flex-wrap gap-2">
                                <span class="info-badge">
                                    <i class="fas fa-users me-1"></i> <?php echo $stat_anggota['aktif']; ?> Anggota Aktif
                                </span>
                                <span class="info-badge">
                                    <i class="fas fa-piggy-bank me-1"></i> Simpanan: <?php echo formatRupiah($total_simpanan, true); ?>
                                </span>
                                <span class="info-badge">
                                    <i class="fas fa-hand-holding-usd me-1"></i> Pinjaman: <?php echo formatRupiah($pinjaman_stats['total_sisa_pokok']); ?>
                                </span>
                                <span class="info-badge">
                                    <i class="fas fa-wallet me-1"></i> Kas: <?php echo formatRupiah($stat_kas['saldo'], true); ?>
                                </span>
                                <span class="info-badge">
                                    <i class="fas fa-store me-1"></i> Waserda: <?php echo formatRupiah($waserda_stats['total_nominal'], true); ?>
                                </span>
                            </div>
                        </div>
                        <div class="col-md-4 text-end">
                            <i class="fas fa-user-cog fa-3x opacity-75"></i>
                        </div>
                    </div>
                </div>

                <!-- Statistics Cards - Baris 1 (Data Utama) -->
                <div class="row stats-section">
                    <!-- Total Anggota -->
                    <div class="col-6 col-md-3 col-xl-2">
                        <div class="stat-card primary h-100">
                            <div class="card-body">
                                <div class="row align-items-center">
                                    <div class="col-8">
                                        <h6 class="card-title text-muted mb-0 small">TOTAL ANGGOTA</h6>
                                        <h4 class="fw-bold text-primary mb-0 mt-1"><?php echo number_format($stat_anggota['total']); ?></h4>
                                        <small class="text-success">
                                            <i class="fas fa-users me-1"></i> Aktif: <?php echo $stat_anggota['aktif']; ?>
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
                    
                    <!-- Total Simpanan -->
                    <div class="col-6 col-md-3 col-xl-2">
                        <div class="stat-card success h-100">
                            <div class="card-body">
                                <div class="row align-items-center">
                                    <div class="col-8">
                                        <h6 class="card-title text-muted mb-0 small">TOTAL SIMPANAN</h6>
                                        <h4 class="fw-bold text-success mb-0 mt-1"><?php echo formatRupiah($total_simpanan, true); ?></h4>
                                        <small class="text-muted">
                                            <i class="fas fa-piggy-bank me-1"></i> Simpanan
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
                    <div class="col-6 col-md-3 col-xl-2 mt-2 mt-md-0">
                        <div class="stat-card danger h-100">
                            <div class="card-body">
                                <div class="row align-items-center">
                                    <div class="col-8">
                                        <h6 class="card-title text-muted mb-0 small">TOTAL PINJAMAN</h6>
                                        <h4 class="fw-bold text-danger mb-0 mt-1"><?php echo formatRupiah($pinjaman_stats['total_nominal'], true); ?></h4>
                                        <small class="text-muted">
                                            <i class="fas fa-hand-holding-usd me-1"></i> <?php echo $pinjaman_stats['aktif']; ?> aktif
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
                    <div class="col-6 col-md-3 col-xl-2 mt-2 mt-md-0">
                        <div class="stat-card warning h-100">
                            <div class="card-body">
                                <div class="row align-items-center">
                                    <div class="col-8">
                                        <h6 class="card-title text-muted mb-0 small">SISA PINJAMAN</h6>
                                        <h4 class="fw-bold text-warning mb-0 mt-1"><?php echo formatRupiah($pinjaman_stats['total_sisa_pokok']); ?></h4>
                                        <small class="text-muted">
                                            <i class="fas fa-hourglass-half me-1"></i> Harus dilunasi
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
                    
                    <!-- Saldo Kas -->
                    <div class="col-6 col-md-3 col-xl-2 mt-2 mt-xl-0">
                        <div class="stat-card info h-100">
                            <div class="card-body">
                                <div class="row align-items-center">
                                    <div class="col-8">
                                        <h6 class="card-title text-muted mb-0 small">SALDO KAS</h6>
                                        <h4 class="fw-bold text-info mb-0 mt-1"><?php echo formatRupiah($stat_kas['saldo']); ?></h4>
                                        <small class="text-muted">
                                            <i class="fas fa-wallet me-1"></i> Tersedia
                                        </small>
                                    </div>
                                    <div class="col-4 text-end">
                                        <div class="stat-icon bg-info text-white">
                                            <i class="fas fa-wallet"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Total Waserda -->
                    <div class="col-6 col-md-3 col-xl-2 mt-2 mt-xl-0">
                        <div class="stat-card waserda h-100">
                            <div class="card-body">
                                <div class="row align-items-center">
                                    <div class="col-8">
                                        <h6 class="card-title text-muted mb-0 small">TOTAL WASERDA</h6>
                                        <h4 class="fw-bold text-success mb-0 mt-1"><?php echo formatRupiah($waserda_stats['total_nominal']); ?></h4>
                                        <small class="text-muted">
                                            <i class="fas fa-store me-1"></i> <?php echo $waserda_stats['total_transaksi']; ?> transaksi
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

                <!-- Statistics Cards - Baris 2 (Data Tambahan) -->
                <div class="row stats-section">
                    <!-- Pengajuan Menunggu -->
                    <div class="col-6 col-md-4 col-xl-3">
                        <div class="stat-card warning h-100">
                            <div class="card-body">
                                <div class="row align-items-center">
                                    <div class="col-8">
                                        <h6 class="card-title text-muted mb-0 small">PENGAJUAN MENUNGGU</h6>
                                        <h4 class="fw-bold text-warning mb-0 mt-1"><?php echo $pengajuan_stats['menunggu']; ?></h4>
                                        <small class="text-muted">
                                            <i class="fas fa-clock me-1"></i> Perlu persetujuan
                                        </small>
                                    </div>
                                    <div class="col-4 text-end">
                                        <div class="stat-icon bg-warning text-white">
                                            <i class="fas fa-clock"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Waserda Hari Ini -->
                    <div class="col-6 col-md-4 col-xl-3 mt-2 mt-md-0">
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
                                            <i class="fas fa-calendar-day"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Angsuran Jatuh Tempo -->
                    <div class="col-6 col-md-4 col-xl-3 mt-2 mt-md-0">
                        <div class="stat-card danger h-100">
                            <div class="card-body">
                                <div class="row align-items-center">
                                    <div class="col-8">
                                        <h6 class="card-title text-muted mb-0 small">JATUH TEMPO</h6>
                                        <h4 class="fw-bold text-danger mb-0 mt-1"><?php echo count($jatuh_tempo); ?></h4>
                                        <small class="text-muted">
                                            <i class="fas fa-exclamation-triangle me-1"></i> 7 hari ke depan
                                        </small>
                                    </div>
                                    <div class="col-4 text-end">
                                        <div class="stat-icon bg-danger text-white">
                                            <i class="fas fa-exclamation-triangle"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Total Angsuran -->
                    <div class="col-6 col-md-4 col-xl-3 mt-2 mt-md-0">
                        <div class="stat-card info h-100">
                            <div class="card-body">
                                <div class="row align-items-center">
                                    <div class="col-8">
                                        <h6 class="card-title text-muted mb-0 small">TOTAL ANGSURAN</h6>
                                        <h4 class="fw-bold text-info mb-0 mt-1"><?php echo formatRupiah($angsuran_stats['total_nominal']); ?></h4>
                                        <small class="text-muted">
                                            <i class="fas fa-credit-card me-1"></i> <?php echo $angsuran_stats['lunas']; ?> lunas
                                        </small>
                                    </div>
                                    <div class="col-4 text-end">
                                        <div class="stat-icon bg-info text-white">
                                            <i class="fas fa-credit-card"></i>
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
                                
                                if (empty($simpanan_per_jenis)): ?>
                                    <div class="col-12 text-center py-4">
                                        <i class="fas fa-info-circle fa-3x text-info mb-3"></i>
                                        <p class="text-muted">Belum ada data simpanan</p>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($simpanan_per_jenis as $s): 
                                        $icon = $icons[$s['kode_jenis']] ?? 'piggy-bank';
                                    ?>
                                    <div class="col-xl-2 col-md-4 col-6 mb-3">
                                        <div class="p-3 bg-light rounded text-center h-100">
                                            <i class="fas fa-<?php echo $icon; ?> fa-2x mb-2 text-<?php echo $s['total_saldo'] > 0 ? 'success' : 'secondary'; ?>"></i>
                                            <h6 class="mb-1"><?php echo $s['kode_jenis']; ?></h6>
                                            <h6 class="fw-bold <?php echo $s['total_saldo'] > 0 ? 'text-success' : 'text-muted'; ?>">
                                                <?php echo formatRupiah($s['total_saldo'], true); ?>
                                            </h6>
                                            <small class="text-muted"><?php echo $s['nama_jenis']; ?></small>
                                            <?php if ($s['jumlah_anggota'] > 0): ?>
                                            <br><small class="text-primary"><?php echo $s['jumlah_anggota']; ?> anggota</small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tiga Kolom: Transaksi Simpanan, Angsuran Terbaru, Waserda Terbaru -->
                <div class="row mt-2">
                    <!-- Transaksi Simpanan Terbaru -->
                    <div class="col-lg-4">
                        <div class="recent-activity">
                            <h5 class="section-title">
                                <i class="fas fa-exchange-alt text-success me-2"></i>Transaksi Simpanan
                                <small class="text-muted">(setoran/penarikan)</small>
                            </h5>
                            
                            <?php if (!empty($transaksi_terbaru)): ?>
                                <?php foreach ($transaksi_terbaru as $t): ?>
                                <div class="d-flex justify-content-between align-items-center border-bottom py-2">
                                    <div>
                                        <small><?php echo date('d/m H:i', strtotime($t['tanggal_transaksi'])); ?></small>
                                        <br><small><?php echo htmlspecialchars($t['nama_lengkap']); ?></small>
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
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-center py-3">
                                    <p class="text-muted">Belum ada transaksi simpanan</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Pembayaran Angsuran Terbaru -->
                    <div class="col-lg-4">
                        <div class="recent-activity">
                            <h5 class="section-title">
                                <i class="fas fa-credit-card text-warning me-2"></i>Pembayaran Angsuran
                            </h5>
                            
                            <?php if (!empty($angsuran_terbaru)): ?>
                                <?php foreach ($angsuran_terbaru as $a): ?>
                                <div class="d-flex justify-content-between align-items-center border-bottom py-2">
                                    <div>
                                        <small><?php echo date('d/m', strtotime($a['tanggal_bayar'])); ?></small>
                                        <br><small><?php echo htmlspecialchars($a['nama_lengkap']); ?></small>
                                    </div>
                                    <div class="text-end">
                                        <span class="badge bg-info">#<?php echo $a['angsuran_ke']; ?></span>
                                        <br><small class="fw-bold text-success">
                                            <?php echo formatRupiah($a['jumlah_angsuran'] + $a['denda'], true); ?>
                                        </small>
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

                    <!-- Waserda Terbaru -->
                    <div class="col-lg-4">
                        <div class="recent-activity">
                            <h5 class="section-title">
                                <i class="fas fa-store text-success me-2"></i>Transaksi Waserda Terbaru
                            </h5>
                            
                            <?php if (!empty($waserda_terbaru)): ?>
                                <?php foreach ($waserda_terbaru as $w): 
                                    $items = json_decode($w['items'], true);
                                    $jumlah_item = count($items);
                                ?>
                                <div class="d-flex justify-content-between align-items-center border-bottom py-2">
                                    <div>
                                        <small><?php echo date('d/m H:i', strtotime($w['created_at'])); ?></small>
                                        <br><small><?php echo htmlspecialchars($w['nama_lengkap']); ?></small>
                                        <br><small class="text-muted"><?php echo $jumlah_item; ?> item</small>
                                    </div>
                                    <div class="text-end">
                                        <span class="badge bg-success">Waserda</span>
                                        <br><small class="fw-bold text-success">
                                            <?php echo formatRupiah($w['total_bayar'], true); ?>
                                        </small>
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

                <!-- Quick Actions - TANPA SIMPANAN -->
                <div class="row mt-3">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h6 class="card-title mb-3"><i class="fas fa-bolt me-2"></i>Aksi Cepat</h6>
                                <div class="row g-2">
                                    <div class="col-md-2 col-6">
                                        <a href="pengajuan.php" class="btn btn-warning w-100 py-2">
                                            <i class="fas fa-file-alt me-1"></i> Pengajuan
                                        </a>
                                    </div>
                                    <div class="col-md-2 col-6">
                                        <a href="angsuran.php" class="btn btn-primary w-100 py-2">
                                            <i class="fas fa-credit-card me-1"></i> Angsuran
                                        </a>
                                    </div>
                                    <div class="col-md-2 col-6">
                                        <a href="pelunasan.php" class="btn btn-success w-100 py-2">
                                            <i class="fas fa-flag-checkered me-1"></i> Pelunasan
                                        </a>
                                    </div>
                                    <div class="col-md-2 col-6">
                                        <a href="anggota.php" class="btn btn-info w-100 py-2 text-white">
                                            <i class="fas fa-users me-1"></i> Anggota
                                        </a>
                                    </div>
                                    <div class="col-md-2 col-6">
                                        <a href="pembelian.php" class="btn btn-waserda w-100 py-2" style="background-color: #28a745; color: white;">
                                            <i class="fas fa-store me-1"></i> Waserda
                                        </a>
                                    </div>
                                    <div class="col-md-2 col-6">
                                        <a href="laporan_kas.php" class="btn btn-secondary w-100 py-2">
                                            <i class="fas fa-file-alt me-1"></i> Laporan Kas
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Auto Refresh -->
                <div class="text-end refresh-time">
                    <i class="fas fa-sync-alt me-1"></i> Auto refresh setiap 30 detik
                </div>
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