<?php
// PERBAIKAN: Include config dan auth yang benar
require_once 'config/config.php';
require_once 'includes/auth.php';

// Redirect jika sudah login - PERBAIKAN: Gunakan function dari auth.php
if (isLoggedIn()) {
    redirectBasedOnRole();
}

// PERBAIKAN: Ambil data koperasi dan statistik dari database
try {
    require_once 'config/database.php';
    $database = new Database();
    $db = $database->getConnection();
    
    // Ambil data koperasi
    $koperasi_query = "SELECT * FROM koperasi WHERE id = 1";
    $koperasi_stmt = $db->prepare($koperasi_query);
    $koperasi_stmt->execute();
    $koperasi = $koperasi_stmt->fetch(PDO::FETCH_ASSOC);
    
    // Jika tidak ada data koperasi, buat default
    if (!$koperasi) {
        $koperasi = [
            'nama' => 'Koperasi Simpan Pinjam Sejahtera',
            'alamat' => 'Jl. Koperasi No. 123, Jakarta',
            'telepon' => '(021) 123-4567',
            'email' => 'info@koperasi.com',
            'rekening_bank' => '-',
            'logo' => null
        ];
    }
    
    // Tentukan path logo yang benar
    $logo_path = '';
    if (!empty($koperasi['logo'])) {
        // Coba beberapa kemungkinan path
        $possible_paths = [
            'assets/uploads/logo/' . $koperasi['logo'],
            'uploads/logo/' . $koperasi['logo'],
            'admin/assets/uploads/logo/' . $koperasi['logo'],
            'admin/uploads/logo/' . $koperasi['logo']
        ];
        
        foreach ($possible_paths as $path) {
            if (file_exists($path)) {
                $logo_path = $path;
                break;
            }
        }
    }
    
    // Ambil statistik real-time dari database
    $stats = [];
    
    // Total anggota aktif
    $query = "SELECT COUNT(*) as total FROM anggota WHERE status = 'aktif'";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $stats['anggota_aktif'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
    // Total simpanan
    $query = "SELECT COALESCE(SUM(saldo), 0) as total FROM simpanan";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $stats['total_simpanan'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
    // Total pinjaman aktif
    $query = "SELECT COUNT(*) as total FROM pinjaman WHERE status = 'aktif'";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $stats['pinjaman_aktif'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
    // Total transaksi bulan ini
    $query = "SELECT COUNT(*) as total FROM transaksi WHERE MONTH(tanggal_transaksi) = MONTH(CURRENT_DATE()) AND YEAR(tanggal_transaksi) = YEAR(CURRENT_DATE())";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $stats['transaksi_bulan_ini'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
    // Total nilai simpanan (format Rp)
    $stats['total_simpanan_format'] = formatRupiahSimple($stats['total_simpanan']);
    
    // Total anggota
    $query = "SELECT COUNT(*) as total FROM anggota";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $stats['total_anggota'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
    // Total pinjaman lunas
    $query = "SELECT COUNT(*) as total FROM pinjaman WHERE status = 'lunas'";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $stats['pinjaman_lunas'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
    // Jenis simpanan
    $query = "SELECT COUNT(*) as total FROM jenis_simpanan WHERE status = 'aktif'";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $stats['jenis_simpanan'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
    // Jenis pinjaman
    $query = "SELECT COUNT(*) as total FROM jenis_pinjaman WHERE status = 'aktif'";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $stats['jenis_pinjaman'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
} catch (Exception $e) {
    // Fallback jika database error
    error_log("Index page database error: " . $e->getMessage());
    $koperasi = [
        'nama' => APP_NAME,
        'alamat' => 'Jl. Koperasi No. 123, Jakarta',
        'telepon' => '(021) 123-4567',
        'email' => 'info@koperasi.com',
        'rekening_bank' => '-',
        'logo' => null
    ];
    $logo_path = '';
    
    // Default stats jika koneksi database gagal
    $stats = [
        'anggota_aktif' => 0,
        'total_simpanan' => 0,
        'total_simpanan_format' => 'Rp 0',
        'pinjaman_aktif' => 0,
        'transaksi_bulan_ini' => 0,
        'total_anggota' => 0,
        'pinjaman_lunas' => 0,
        'jenis_simpanan' => 0,
        'jenis_pinjaman' => 0
    ];
}

// Fungsi format rupiah sederhana
function formatRupiahSimple($angka) {
    if ($angka >= 1000000000) {
        return 'Rp ' . number_format($angka / 1000000000, 1) . ' M';
    } elseif ($angka >= 1000000) {
        return 'Rp ' . number_format($angka / 1000000, 1) . ' Jt';
    } elseif ($angka >= 1000) {
        return 'Rp ' . number_format($angka / 1000, 0) . ' Rb';
    }
    return 'Rp ' . number_format($angka, 0, ',', '.');
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title><?php echo htmlspecialchars($koperasi['nama']); ?> - Sistem Manajemen Koperasi Modern</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary: #2c3e50;
            --secondary: #3498db;
            --success: #27ae60;
            --warning: #f39c12;
            --danger: #e74c3c;
            --light: #ecf0f1;
            --dark: #34495e;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f8f9fa;
            overflow-x: hidden;
        }
        
        /* Navigation */
        .navbar {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 0.8rem 1rem;
        }
        
        .navbar-brand {
            font-weight: 700;
            font-size: 1.3rem;
            display: flex;
            align-items: center;
        }
        
        .navbar-brand img {
            height: 35px;
            width: 35px;
            object-fit: cover;
            border-radius: 50%;
            margin-right: 10px;
            border: 2px solid rgba(255,255,255,0.3);
        }
        
        @media (max-width: 576px) {
            .navbar-brand {
                font-size: 1.1rem;
            }
            
            .navbar-brand img {
                height: 30px;
                width: 30px;
            }
        }
        
        .btn-login {
            background: rgba(255,255,255,0.2);
            border: 2px solid rgba(255,255,255,0.3);
            color: white;
            border-radius: 25px;
            padding: 0.5rem 1.5rem;
            transition: all 0.3s ease;
        }
        
        .btn-login:hover {
            background: rgba(255,255,255,0.3);
            transform: translateY(-2px);
        }
        
        @media (max-width: 576px) {
            .btn-login {
                padding: 0.4rem 1rem;
                font-size: 0.9rem;
            }
        }
        
        /* Hero Section */
        .hero-section {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            padding: 4rem 0;
            position: relative;
            overflow: hidden;
        }
        
        @media (min-width: 768px) {
            .hero-section {
                padding: 6rem 0;
            }
        }
        
        .hero-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1000 1000"><polygon fill="rgba(255,255,255,0.05)" points="0,1000 1000,0 1000,1000"/></svg>');
            background-size: cover;
        }
        
        .hero-content {
            position: relative;
            z-index: 2;
        }
        
        .hero-badge {
            background: rgba(255,255,255,0.2);
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.9rem;
            display: inline-block;
            margin-bottom: 1rem;
        }
        
        @media (max-width: 576px) {
            .hero-badge {
                font-size: 0.8rem;
                padding: 0.4rem 0.8rem;
            }
        }
        
        .hero-title {
            font-size: 1.8rem;
            line-height: 1.3;
        }
        
        @media (min-width: 576px) {
            .hero-title {
                font-size: 2.2rem;
            }
        }
        
        @media (min-width: 768px) {
            .hero-title {
                font-size: 2.5rem;
            }
        }
        
        @media (min-width: 992px) {
            .hero-title {
                font-size: 3rem;
            }
        }
        
        .hero-logo {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            border: 5px solid rgba(255,255,255,0.3);
            object-fit: cover;
            margin-bottom: 1.5rem;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }
        
        @media (max-width: 768px) {
            .hero-logo {
                width: 120px;
                height: 120px;
            }
        }
        
        /* Features Section */
        .features-section {
            padding: 3rem 0;
            background: white;
        }
        
        @media (min-width: 768px) {
            .features-section {
                padding: 5rem 0;
            }
        }
        
        .feature-card {
            background: white;
            border-radius: 15px;
            padding: 2rem 1.5rem;
            text-align: center;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            border: 1px solid rgba(0,0,0,0.05);
            transition: all 0.3s ease;
            height: 100%;
        }
        
        @media (max-width: 576px) {
            .feature-card {
                padding: 1.5rem 1rem;
            }
        }
        
        .feature-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 20px 40px rgba(0,0,0,0.15);
        }
        
        .feature-icon {
            width: 70px;
            height: 70px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
            color: white;
            font-size: 1.8rem;
        }
        
        @media (max-width: 576px) {
            .feature-icon {
                width: 60px;
                height: 60px;
                font-size: 1.5rem;
            }
        }
        
        /* Stats Section */
        .stats-section {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            padding: 3rem 0;
        }
        
        @media (min-width: 768px) {
            .stats-section {
                padding: 4rem 0;
            }
        }
        
        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.3rem;
        }
        
        @media (min-width: 576px) {
            .stat-number {
                font-size: 2.2rem;
            }
        }
        
        @media (min-width: 768px) {
            .stat-number {
                font-size: 2.5rem;
            }
        }
        
        @media (min-width: 992px) {
            .stat-number {
                font-size: 3rem;
            }
        }
        
        .stat-label {
            font-size: 0.9rem;
            opacity: 0.9;
        }
        
        @media (max-width: 576px) {
            .stat-label {
                font-size: 0.8rem;
            }
        }
        
        /* Services Section */
        .services-section {
            padding: 3rem 0;
            background: var(--light);
        }
        
        @media (min-width: 768px) {
            .services-section {
                padding: 5rem 0;
            }
        }
        
        .service-item {
            background: white;
            border-radius: 15px;
            padding: 1.8rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
        }
        
        @media (max-width: 576px) {
            .service-item {
                padding: 1.5rem;
            }
        }
        
        .service-item:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 30px rgba(0,0,0,0.15);
        }
        
        .service-icon {
            width: 50px;
            height: 50px;
            background: var(--primary);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.3rem;
            margin-bottom: 1rem;
        }
        
        /* About Section */
        .about-section {
            padding: 3rem 0;
            background: white;
        }
        
        @media (min-width: 768px) {
            .about-section {
                padding: 5rem 0;
            }
        }
        
        .about-image {
            border-radius: 15px;
            box-shadow: 0 15px 30px rgba(0,0,0,0.1);
            padding: 2rem;
            margin-top: 2rem;
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
        }
        
        .about-logo {
            width: 200px;
            height: 200px;
            border-radius: 50%;
            object-fit: cover;
            border: 5px solid var(--primary);
            box-shadow: 0 10px 30px rgba(0,0,0,0.15);
        }
        
        @media (max-width: 768px) {
            .about-image {
                margin-top: 0;
            }
            
            .about-logo {
                width: 150px;
                height: 150px;
            }
        }
        
        /* Products Section */
        .products-section {
            padding: 3rem 0;
            background: white;
        }
        
        @media (min-width: 768px) {
            .products-section {
                padding: 5rem 0;
            }
        }
        
        .product-card {
            background: white;
            border-radius: 15px;
            padding: 1.8rem;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
            height: 100%;
            border: 1px solid #f0f0f0;
        }
        
        .product-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 30px rgba(0,0,0,0.12);
        }
        
        .product-icon {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
            margin-bottom: 1.2rem;
        }
        
        .product-price {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--success);
            margin: 1rem 0;
        }
        
        /* Footer */
        .footer {
            background: var(--dark);
            color: white;
            padding: 2.5rem 0 1.5rem;
        }
        
        @media (min-width: 768px) {
            .footer {
                padding: 3rem 0 2rem;
            }
        }
        
        .footer-links a {
            color: rgba(255,255,255,0.7);
            text-decoration: none;
            transition: color 0.3s ease;
            font-size: 0.9rem;
        }
        
        .footer-links a:hover {
            color: white;
        }
        
        .footer-logo {
            height: 50px;
            width: 50px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid rgba(255,255,255,0.3);
            margin-right: 10px;
        }
        
        /* Floating Shapes */
        .floating-shape {
            position: absolute;
            border-radius: 50%;
            background: rgba(255,255,255,0.1);
            animation: float 6s ease-in-out infinite;
        }
        
        .shape-1 {
            width: 100px;
            height: 100px;
            top: 20%;
            left: 10%;
            animation-delay: 0s;
        }
        
        .shape-2 {
            width: 150px;
            height: 150px;
            bottom: 20%;
            right: 10%;
            animation-delay: -2s;
        }
        
        @media (max-width: 768px) {
            .shape-1 {
                width: 60px;
                height: 60px;
            }
            
            .shape-2 {
                width: 80px;
                height: 80px;
            }
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-20px); }
        }
        
        /* Section Headers */
        .section-title {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 1rem;
        }
        
        @media (min-width: 576px) {
            .section-title {
                font-size: 2rem;
            }
        }
        
        @media (min-width: 768px) {
            .section-title {
                font-size: 2.2rem;
            }
        }
        
        /* CTA Buttons */
        .btn-cta {
            padding: 0.6rem 1.5rem;
            font-size: 1rem;
        }
        
        @media (max-width: 576px) {
            .btn-cta {
                padding: 0.5rem 1rem;
                font-size: 0.9rem;
            }
        }
        
        /* Animations */
        .fade-in {
            animation: fadeIn 1s ease-in;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        /* Touch-friendly */
        .btn, .nav-link, .feature-card, .service-item, .product-card {
            cursor: pointer;
            -webkit-tap-highlight-color: transparent;
        }
        
        .btn:active, .feature-card:active, .service-item:active, .product-card:active {
            transform: scale(0.98);
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark sticky-top">
        <div class="container">
            <a class="navbar-brand" href="#">
                <?php if (!empty($logo_path)): ?>
                    <img src="<?php echo htmlspecialchars($logo_path); ?>" 
                         alt="Logo <?php echo htmlspecialchars($koperasi['nama']); ?>"
                         onerror="this.style.display='none'; this.nextElementSibling.style.display='inline-block';">
                    <i class="fas fa-hand-holding-usd me-2" style="display: none;"></i>
                <?php else: ?>
                    <i class="fas fa-hand-holding-usd me-2"></i>
                <?php endif; ?>
                <?php echo htmlspecialchars($koperasi['nama']); ?>
            </a>
            
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="#home">Beranda</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#tentang">Tentang</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#layanan">Layanan</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#produk">Produk</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#kontak">Kontak</a>
                    </li>
                </ul>
                
                <div class="d-flex">
                    <a href="login.php" class="btn btn-login">
                        <i class="fas fa-sign-in-alt me-2"></i>Login
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section id="home" class="hero-section">
        <div class="floating-shape shape-1"></div>
        <div class="floating-shape shape-2"></div>
        
        <div class="container">
            <div class="row align-items-center">
                <div class="col-lg-6 hero-content fade-in">
                    <?php if (!empty($logo_path)): ?>
                        <img src="<?php echo htmlspecialchars($logo_path); ?>" 
                             alt="Logo <?php echo htmlspecialchars($koperasi['nama']); ?>"
                             class="hero-logo d-block d-lg-none mx-auto mb-4"
                             onerror="this.style.display='none';">
                    <?php endif; ?>
                    
                    <span class="hero-badge">
                        <i class="fas fa-rocket me-2"></i>Sistem Manajemen Modern
                    </span>
                    <h1 class="hero-title fw-bold mb-3">
                        Kelola kuangan Anda dengan <span class="text-warning">Mudah</span> dan <span class="text-warning">Efisien</span>
                    </h1>
                    <p class="lead mb-4 opacity-75">
                        <?php echo htmlspecialchars($koperasi['nama']); ?> memberikan solusi lengkap untuk mengelola seluruh aktivitas keuangan secara digital. 
                        Dari simpanan, pinjaman, hingga laporan keuangan - semuanya dalam satu platform.
                    </p>
                    <div class="d-flex flex-wrap gap-2 gap-md-3">
                        <a href="#layanan" class="btn btn-warning btn-cta">
                            <i class="fas fa-play-circle me-2"></i>Mulai Sekarang
                        </a>
                        <a href="#tentang" class="btn btn-outline-light btn-cta">
                            <i class="fas fa-info-circle me-2"></i>Pelajari Lebih
                        </a>
                    </div>
                </div>
                <div class="col-lg-6 text-center mt-4 mt-lg-0">
                    <?php if (!empty($logo_path)): ?>
                        <img src="<?php echo htmlspecialchars($logo_path); ?>" 
                             alt="Logo <?php echo htmlspecialchars($koperasi['nama']); ?>"
                             class="hero-logo d-none d-lg-inline-block"
                             onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                        <div class="feature-icon mx-auto d-none d-lg-flex" style="width: 150px; height: 150px; font-size: 4rem; background: rgba(255,255,255,0.1); display: none;">
                            <i class="fas fa-hand-holding-usd"></i>
                        </div>
                    <?php else: ?>
                        <div class="feature-icon mx-auto" style="width: 150px; height: 150px; font-size: 4rem; background: rgba(255,255,255,0.1);">
                            <i class="fas fa-hand-holding-usd"></i>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section class="features-section">
        <div class="container">
            <div class="row justify-content-center mb-4 mb-md-5">
                <div class="col-lg-8 text-center">
                    <h2 class="section-title text-primary">Mengapa Memilih Sistem Kami?</h2>
                    <p class="lead text-muted">Platform terintegrasi yang dirancang khusus untuk kebutuhan simpan pinjam modern</p>
                </div>
            </div>
            
            <div class="row g-3 g-md-4">
                <div class="col-md-4">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="fas fa-shield-alt"></i>
                        </div>
                        <h4 class="fw-bold mb-3">Keamanan Terjamin</h4>
                        <p class="text-muted mb-0">
                            Sistem keamanan berlapis dengan enkripsi data untuk melindungi informasi anggota dan transaksi keuangan.
                        </p>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="fas fa-bolt"></i>
                        </div>
                        <h4 class="fw-bold mb-3">Proses Real-time</h4>
                        <p class="text-muted mb-0">
                            Transaksi dan pembaruan data terjadi secara real-time, memastikan akurasi dan kecepatan proses dan pembayaran yang transparan.
                        </p>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <h4 class="fw-bold mb-3">Analitik Lengkap</h4>
                        <p class="text-muted mb-0">
                            Dashboard analitik yang komprehensif untuk monitoring performa pinjaman dan pengambilan keputusan.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Stats Section - Data Real-time dari Database -->
    <section class="stats-section">
        <div class="container">
            <div class="row text-center">
                <div class="col-6 col-md-3 mb-4">
                    <div class="stat-number">
                        <?php echo number_format($stats['anggota_aktif']); ?>
                    </div>
                    <div class="stat-label">Anggota Aktif</div>
                </div>
                
                <div class="col-6 col-md-3 mb-4">
                    <div class="stat-number">
                        <?php echo $stats['total_simpanan_format']; ?>
                    </div>
                    <div class="stat-label">Total Simpanan</div>
                </div>
                
                <div class="col-6 col-md-3 mb-4">
                    <div class="stat-number">
                        <?php echo number_format($stats['pinjaman_aktif']); ?>
                    </div>
                    <div class="stat-label">Pinjaman Aktif</div>
                </div>
                
                <div class="col-6 col-md-3 mb-4">
                    <div class="stat-number">
                        <?php echo number_format($stats['transaksi_bulan_ini']); ?>
                    </div>
                    <div class="stat-label">Transaksi/Bulan</div>
                </div>
            </div>
        </div>
    </section>

    <!-- Services Section -->
    <section id="layanan" class="services-section">
        <div class="container">
            <div class="row justify-content-center mb-4 mb-md-5">
                <div class="col-lg-8 text-center">
                    <h2 class="section-title text-primary">Layanan Unggulan Kami</h2>
                    <p class="lead text-muted">Berbagai layanan tersedia
                    mulai dari pinjaman yang mana pinjaman tersebut bisa cair hari itu juga, proses kurang lebih 30 menit bisa langsung di cairkan dan yang paling utama,Nasabah cari tanpa potongan sama sekali/100% terima penuh</p>
                </div>
            </div>
            
            <div class="row">
                <div class="col-lg-6">
                    <div class="service-item">
                        <div class="service-icon bg-primary">
                            <i class="fas fa-piggy-bank"></i>
                        </div>
                        <h4 class="fw-bold mb-3">Manajemen Simpanan</h4>
                        <p class="text-muted mb-3">
                            Kelola <?php echo $stats['jenis_simpanan']; ?> jenis simpanan anggota mulai dari simpanan pokok, wajib, sukarela dan gadai
                            hingga simpanan berjangka dengan sistem bagi hasil yang kompetitif.
                        </p>
                        <ul class="list-unstyled">
                            <li><i class="fas fa-check text-success me-2"></i> Multiple jenis simpanan</li>
                            <li><i class="fas fa-check text-success me-2"></i> Bagi hasil otomatis</li>
                            <li><i class="fas fa-check text-success me-2"></i> Laporan real-time</li>
                        </ul>
                    </div>
                </div>
                
                <div class="col-lg-6">
                    <div class="service-item">
                        <div class="service-icon bg-success">
                            <i class="fas fa-hand-holding-usd"></i>
                        </div>
                        <h4 class="fw-bold mb-3">Sistem Pinjaman</h4>
                        <p class="text-muted mb-3">
                            Fasilitas <?php echo $stats['jenis_pinjaman']; ?> jenis pinjaman dengan bunga ringan untuk berbagai keperluan 
                            seperti modal usaha, pendidikan, kesehatan, dan kebutuhan mendesak.
                            pencairan terima bersih 100% penuh/tanpa potongan.
                        </p>
                        <ul class="list-unstyled">
                            <li><i class="fas fa-check text-success me-2"></i> Berbagai jenis pinjaman</li>
                            <li><i class="fas fa-check text-success me-2"></i> Tracking angsuran</li>
                            <li><i class="fas fa-check text-success me-2"></i> Sistem denda otomatis</li>
                        </ul>
                    </div>
                </div>
                
                <div class="col-lg-6">
                    <div class="service-item">
                        <div class="service-icon bg-warning">
                            <i class="fas fa-exchange-alt"></i>
                        </div>
                        <h4 class="fw-bold mb-3">Transaksi & Pembayaran</h4>
                        <p class="text-muted mb-3">
                            Sistem transaksi yang lengkap termasuk setoran, penarikan, pembayaran tagihan listrik, 
                            pembelian token, dan berbagai layanan pembayaran lainnya.
                        </p>
                        <ul class="list-unstyled">
                            <li><i class="fas fa-check text-success me-2"></i> Multi-payment system</li>
                            <li><i class="fas fa-check text-success me-2"></i> Riwayat transaksi</li>
                            <li><i class="fas fa-check text-success me-2"></i> Notifikasi real-time</li>
                        </ul>
                    </div>
                </div>
                
                <div class="col-lg-6">
                    <div class="service-item">
                        <div class="service-icon bg-info">
                            <i class="fas fa-chart-bar"></i>
                        </div>
                        <h4 class="fw-bold mb-3">Laporan & Analitik</h4>
                        <p class="text-muted mb-3">
                            Dashboard analitik yang komprehensif dengan berbagai laporan keuangan, performa simpanan/pinjaman, 
                            dan insights untuk pengambilan keputusan strategis.
                        </p>
                        <ul class="list-unstyled">
                            <li><i class="fas fa-check text-success me-2"></i> Laporan keuangan</li>
                            <li><i class="fas fa-check text-success me-2"></i> Grafik interaktif</li>
                            <li><i class="fas fa-check text-success me-2"></i> Export berbagai format</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Products Section - Data dari Database -->
    <section id="produk" class="products-section">
        <div class="container">
            <div class="row justify-content-center mb-4 mb-md-5">
                <div class="col-lg-8 text-center">
                    <h2 class="section-title text-primary">Produk Simpanan & Pinjaman</h2>
                    <p class="lead text-muted">Pilihan produk terbaik untuk memenuhi kebutuhan finansial/usaha dan umkm anda</p>
                </div>
            </div>
            
            <div class="row g-3 g-md-4">
                <?php
                // Ambil data jenis simpanan dari database
                try {
                    $query_simpanan = "SELECT * FROM jenis_simpanan WHERE status = 'aktif' LIMIT 4";
                    $stmt_simpanan = $db->prepare($query_simpanan);
                    $stmt_simpanan->execute();
                    $jenis_simpanan = $stmt_simpanan->fetchAll(PDO::FETCH_ASSOC);
                    
                    foreach ($jenis_simpanan as $simpanan) {
                ?>
                <div class="col-md-3 col-6">
                    <div class="product-card">
                        <div class="product-icon">
                            <i class="fas fa-piggy-bank"></i>
                        </div>
                        <h5 class="fw-bold"><?php echo htmlspecialchars($simpanan['nama_jenis']); ?></h5>
                        <p class="small text-muted"><?php echo htmlspecialchars($simpanan['deskripsi'] ?: '-'); ?></p>
                        <div class="product-price">
                            <?php echo formatRupiahSimple($simpanan['minimum'] ?? 0); ?>
                        </div>
                        <small class="text-muted">Min. Saldo</small>
                    </div>
                </div>
                <?php 
                    }
                } catch (Exception $e) {
                    // Fallback jika query gagal
                ?>
                <div class="col-md-3 col-6">
                    <div class="product-card">
                        <div class="product-icon">
                            <i class="fas fa-piggy-bank"></i>
                        </div>
                        <h5 class="fw-bold">Simpanan Pokok</h5>
                        <p class="small text-muted">Simpanan awal keanggotaan</p>
                        <div class="product-price">Rp 50 Rb</div>
                        <small class="text-muted">Min. Saldo</small>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="product-card">
                        <div class="product-icon">
                            <i class="fas fa-piggy-bank"></i>
                        </div>
                        <h5 class="fw-bold">Simpanan Wajib</h5>
                        <p class="small text-muted">Simpanan rutin bulanan</p>
                        <div class="product-price">Rp 25 Rb</div>
                        <small class="text-muted">Per Bulan</small>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="product-card">
                        <div class="product-icon">
                            <i class="fas fa-piggy-bank"></i>
                        </div>
                        <h5 class="fw-bold">Simpanan Sukarela</h5>
                        <p class="small text-muted">Simpanan fleksibel</p>
                        <div class="product-price">Rp 0</div>
                        <small class="text-muted">Tanpa Minimal</small>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="product-card">
                        <div class="product-icon">
                            <i class="fas fa-piggy-bank"></i>
                        </div>
                        <h5 class="fw-bold">Deposito</h5>
                        <p class="small text-muted">Simpanan berjangka</p>
                        <div class="product-price">Rp 1 Jt</div>
                        <small class="text-muted">Min. Deposit</small>
                    </div>
                </div>
                <?php } ?>
            </div>
        </div>
    </section>

    <!-- About Section -->
    <section id="tentang" class="about-section">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-lg-6">
                    <h2 class="section-title text-primary mb-4">Tentang <?php echo htmlspecialchars($koperasi['nama']); ?></h2>
                    <p class="lead text-muted mb-4">
                        <?php echo htmlspecialchars($koperasi['nama']); ?> adalah platform manajemen simpan keuangan modern yang dikembangkan dengan 
                        teknologi terkini untuk memudahkan pengelolaan seluruh aspek operasional keuangan.
                    </p>
                    <p class="text-muted mb-4">
                        Kami memahami bahwa simpan keuangan membutuhkan sistem yang tidak hanya efisien tetapi juga 
                        mudah digunakan oleh semua kalangan. Dengan antarmuka yang intuitif dan fitur yang lengkap, 
                        kami hadir untuk mendukung pertumbuhan usaha/umkm Anda.
                    </p>
                    <div class="d-flex flex-wrap gap-3 gap-md-4">
                        <div class="text-center">
                            <div class="fw-bold text-primary fs-3"><?php echo number_format($stats['total_anggota']); ?></div>
                            <small class="text-muted">Total Anggota</small>
                        </div>
                        <div class="text-center">
                            <div class="fw-bold text-success fs-3"><?php echo number_format($stats['pinjaman_lunas']); ?></div>
                            <small class="text-muted">Pinjaman Lunas</small>
                        </div>
                        <div class="text-center">
                            <div class="fw-bold text-warning fs-3">24/7</div>
                            <small class="text-muted">Support</small>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6 text-center">
                    <div class="about-image">
                        <?php if (!empty($logo_path)): ?>
                            <img src="<?php echo htmlspecialchars($logo_path); ?>" 
                                 alt="Logo <?php echo htmlspecialchars($koperasi['nama']); ?>"
                                 class="about-logo"
                                 onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                            <i class="fas fa-users fa-6x text-primary opacity-25" style="display: none;"></i>
                        <?php else: ?>
                            <i class="fas fa-users fa-6x text-primary opacity-25"></i>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer id="kontak" class="footer">
        <div class="container">
            <div class="row">
                <div class="col-lg-4 mb-4">
                    <h5 class="fw-bold mb-3 d-flex align-items-center">
                        <?php if (!empty($logo_path)): ?>
                            <img src="<?php echo htmlspecialchars($logo_path); ?>" 
                                 alt="Logo" class="footer-logo"
                                 onerror="this.style.display='none'; this.nextElementSibling.style.display='inline-block';">
                            <i class="fas fa-hand-holding-usd me-2" style="display: none;"></i>
                        <?php else: ?>
                            <i class="fas fa-hand-holding-usd me-2"></i>
                        <?php endif; ?>
                        <?php echo htmlspecialchars($koperasi['nama']); ?>
                    </h5>
                    <p class="text-white-50 mb-3">
                        Platform manajemen pinjaman modern yang membantu Anda mengelola operasional usaha/umkm
                        dengan lebih efisien dan transparan.
                    </p>
                    <div class="d-flex gap-3">
                        <a href="#" class="text-white-50"><i class="fab fa-facebook fa-lg"></i></a>
                        <a href="#" class="text-white-50"><i class="fab fa-twitter fa-lg"></i></a>
                        <a href="#" class="text-white-50"><i class="fab fa-instagram fa-lg"></i></a>
                        <a href="#" class="text-white-50"><i class="fab fa-linkedin fa-lg"></i></a>
                    </div>
                </div>
                
                <div class="col-lg-2 col-6 mb-4">
                    <h6 class="fw-bold mb-3">Navigasi</h6>
                    <div class="footer-links d-flex flex-column">
                        <a href="#home" class="mb-2">Beranda</a>
                        <a href="#tentang" class="mb-2">Tentang</a>
                        <a href="#layanan" class="mb-2">Layanan</a>
                        <a href="#produk" class="mb-2">Produk</a>
                    </div>
                </div>
                
                <div class="col-lg-3 col-6 mb-4">
                    <h6 class="fw-bold mb-3">Layanan</h6>
                    <div class="footer-links d-flex flex-column">
                        <a href="#" class="mb-2">Simpanan</a>
                        <a href="#" class="mb-2">Pinjaman</a>
                        <a href="#" class="mb-2">Transaksi</a>
                        <a href="#" class="mb-2">Laporan</a>
                    </div>
                </div>
                
                <div class="col-lg-3 mb-4">
                    <h6 class="fw-bold mb-3">Kontak</h6>
                    <div class="text-white-50">
                        <p class="mb-2">
                            <i class="fas fa-map-marker-alt me-2"></i>
                            <?php echo htmlspecialchars($koperasi['alamat']); ?>
                        </p>
                        <p class="mb-2">
                            <i class="fas fa-phone me-2"></i>
                            <?php echo htmlspecialchars($koperasi['telepon']); ?>
                        </p>
                        <p class="mb-2">
                            <i class="fas fa-envelope me-2"></i>
                            <?php echo htmlspecialchars($koperasi['email']); ?>
                        </p>
                    </div>
                </div>
            </div>
            
            <hr class="my-4 bg-light opacity-25">
            
            <div class="row align-items-center">
                <div class="col-md-6">
                    <p class="text-white-50 mb-2 mb-md-0 small">
                        &copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($koperasi['nama']); ?>. All rights reserved.
                    </p>
                </div>
                <div class="col-md-6 text-md-end">
                    <small class="text-white-50">
                        v<?php echo APP_VERSION; ?> - <i class="fas fa-heart text-danger"></i> for Indonesian Cooperatives
                    </small>
                </div>
            </div>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Smooth scroll for navigation links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                    
                    // Update active class in navbar
                    document.querySelectorAll('.navbar-nav .nav-link').forEach(link => {
                        link.classList.remove('active');
                    });
                    this.classList.add('active');
                }
            });
        });
        
        // Highlight active section on scroll
        window.addEventListener('scroll', function() {
            const sections = document.querySelectorAll('section[id]');
            const scrollY = window.pageYOffset;
            
            sections.forEach(section => {
                const sectionHeight = section.offsetHeight;
                const sectionTop = section.offsetTop - 100;
                const sectionId = section.getAttribute('id');
                
                if (scrollY > sectionTop && scrollY <= sectionTop + sectionHeight) {
                    document.querySelectorAll('.navbar-nav .nav-link').forEach(link => {
                        link.classList.remove('active');
                        if (link.getAttribute('href') === '#' + sectionId) {
                            link.classList.add('active');
                        }
                    });
                }
            });
        });
        
        // Navbar background on scroll
        window.addEventListener('scroll', function() {
            const navbar = document.querySelector('.navbar');
            if (window.scrollY > 50) {
                navbar.style.background = 'linear-gradient(135deg, #1a252f, #2980b9)';
                navbar.style.boxShadow = '0 2px 20px rgba(0,0,0,0.2)';
            } else {
                navbar.style.background = 'linear-gradient(135deg, var(--primary), var(--secondary))';
                navbar.style.boxShadow = '0 2px 10px rgba(0,0,0,0.1)';
            }
        });
    </script>
</body>
</html>