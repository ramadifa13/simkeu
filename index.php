<?php
require_once __DIR__.'/config/config.php';
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/brand.php';
if(isLoggedIn()){redirectBasedOnRole();}
$koperasi=$db->query('SELECT * FROM koperasi WHERE id=1')->fetch(PDO::FETCH_ASSOC) ?: ['nama'=>APP_NAME];
$nama=$koperasi['nama'];
$simpanan=$db->query("SELECT nama_jenis,deskripsi,minimum FROM jenis_simpanan WHERE status='aktif' AND kode_jenis NOT IN ('ANG','BONUS','SHU') ORDER BY id LIMIT 4")->fetchAll(PDO::FETCH_ASSOC);
$pinjaman=$db->query("SELECT nama_jenis,deskripsi,memerlukan_jaminan FROM jenis_pinjaman WHERE status='aktif' ORDER BY id LIMIT 4")->fetchAll(PDO::FETCH_ASSOC);
function homeEscape($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Layanan simpanan, pinjaman, dan informasi anggota koperasi.">
    <title><?= homeEscape($nama) ?> — Layanan koperasi</title>
    <?php $uiAssetPrefix = ''; include __DIR__ . '/includes/layout/assets.php'; ?>
    <link rel="stylesheet" href="assets/css/public-theme.css?v=<?= filemtime(__DIR__ . '/assets/css/public-theme.css') ?>">
    <script src="assets/js/brand.js?v=20261019-6" defer></script>
</head>
<body class="sk-home">
<a class="visually-hidden-focusable" href="#utama">Lewati ke konten</a>
<nav class="navbar navbar-expand-lg" aria-label="Navigasi utama">
    <div class="container-xl">
        <a class="home-brand" href="index.php"><?= brandLogo($koperasi['logo'] ?? '') ?><span><?= homeEscape($nama) ?><small>Layanan koperasi anggota</small></span></a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#homeLinks" aria-controls="homeLinks" aria-expanded="false" aria-label="Buka navigasi"><span class="navbar-toggler-icon"></span></button>
        <div class="collapse navbar-collapse" id="homeLinks">
            <ul class="navbar-nav ms-auto align-items-lg-center gap-lg-1">
                <li class="nav-item"><a class="nav-link" href="#beranda">Beranda</a></li>
                <li class="nav-item"><a class="nav-link" href="#layanan">Layanan</a></li>
                <li class="nav-item"><a class="nav-link" href="#produk">Produk</a></li>
                <li class="nav-item"><a class="nav-link" href="#tentang">Tentang</a></li>
                <li class="nav-item"><a class="nav-link" href="#kontak">Kontak</a></li>
                <li class="nav-item ms-lg-2"><a class="btn btn-primary" href="login.php">Masuk <i class="ti ti-arrow-right ms-1" aria-hidden="true"></i></a></li>
            </ul>
        </div>
    </div>
</nav>
<main id="utama">
    <section class="sk-hero" id="beranda">
        <div class="container-xl">
            <div class="row align-items-center g-5">
                <div class="col-lg-6">
                    <span class="sk-eyebrow" style="color:var(--tblr-primary)">TUMBUH BERSAMA ANGGOTA</span>
                    <h1>Langkah keuangan<br>lebih <em>tertata.</em></h1>
                    <p class="lead text-secondary my-4">Simpan, ajukan pinjaman, dan pantau pembayaran Anda dalam satu layanan koperasi yang mudah diakses.</p>
                    <div class="d-flex flex-wrap gap-2">
                        <a class="btn btn-primary btn-lg" href="login.php">Masuk ke akun <i class="ti ti-arrow-right ms-1" aria-hidden="true"></i></a>
                        <a class="btn btn-outline-primary btn-lg" href="#produk">Kenali produk kami</a>
                    </div>
                    <p class="text-secondary small mt-3 mb-0"><i class="ti ti-lock me-1" aria-hidden="true"></i>Akses sesuai peran Admin, Teller, dan Anggota</p>
                </div>
                <div class="col-lg-6">
                    <div class="sk-hero-card">
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <span style="width:2.8rem;height:2.8rem;display:inline-flex"><?= brandLogo($koperasi['logo'] ?? '') ?></span>
                            <div><div class="small text-secondary">LAYANAN ANGGOTA</div><strong><?= homeEscape($nama) ?></strong></div>
                        </div>
                        <h2 class="h3 mb-3">Kebutuhan anggota,<br>dalam satu tempat.</h2>
                        <div class="item"><span class="sk-icon-badge"><i class="ti ti-pig-money" aria-hidden="true"></i></span><div class="flex-fill"><strong>Simpanan</strong><div class="small text-secondary">Catat dan pantau saldo simpanan</div></div><i class="ti ti-check text-green" aria-hidden="true"></i></div>
                        <div class="item"><span class="sk-icon-badge"><i class="ti ti-moneybag" aria-hidden="true"></i></span><div class="flex-fill"><strong>Pinjaman &amp; angsuran</strong><div class="small text-secondary">Lihat tagihan dan riwayat pembayaran</div></div><i class="ti ti-check text-green" aria-hidden="true"></i></div>
                        <div class="item"><span class="sk-icon-badge"><i class="ti ti-file-invoice" aria-hidden="true"></i></span><div class="flex-fill"><strong>Informasi SHU</strong><div class="small text-secondary">Lihat pembagian yang telah disahkan</div></div><i class="ti ti-check text-green" aria-hidden="true"></i></div>
                        <div class="small text-secondary mt-2"><i class="ti ti-circle-check text-green me-1" aria-hidden="true"></i>Terhubung melalui akun anggota Anda</div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="sk-section alt" id="layanan">
        <div class="container-xl">
            <div class="row g-4 align-items-end mb-4">
                <div class="col-lg-6"><span class="sk-eyebrow" style="color:var(--tblr-primary)">LAYANAN KOPERASI</span><h2>Lebih mudah mengurus<br>kebutuhan keuangan.</h2></div>
                <div class="col-lg-6"><p class="text-secondary mb-0">Dari pencatatan simpanan hingga pembayaran angsuran, layanan tersedia sesuai kebutuhan dan hak akses Anda.</p></div>
            </div>
            <div class="row g-3">
                <?php foreach ([['ti-pig-money','Simpanan anggota','Kelola setoran dan penarikan, serta lihat riwayat saldo simpanan.'],['ti-moneybag','Pinjaman & angsuran','Ajukan pinjaman melalui Teller dan pantau jadwal pembayaran.'],['ti-receipt-2','Bukti & riwayat transaksi','Telusuri catatan transaksi dan bukti pembayaran yang tersedia.'],['ti-chart-pie','Informasi SHU','Akses informasi pembagian SHU anggota setelah disahkan pengurus.']] as $n => $item): ?>
                <div class="col-sm-6 col-lg-3 sk-reveal"><article class="card sk-service mb-0"><div class="card-body"><span class="sk-icon-badge mb-3"><i class="ti <?= $item[0] ?>" aria-hidden="true"></i></span><h3 class="h4"><?= homeEscape($item[1]) ?></h3><p class="text-secondary mb-0"><?= homeEscape($item[2]) ?></p></div></article></div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="sk-section" id="produk">
        <div class="container-xl">
            <div class="row g-4 align-items-end mb-4">
                <div class="col-lg-6"><span class="sk-eyebrow" style="color:var(--tblr-primary)">PILIHAN UNTUK ANGGOTA</span><h2>Kenali produk koperasi.</h2></div>
                <div class="col-lg-6"><p class="text-secondary mb-0">Hubungi Teller untuk informasi syarat, biaya, dan ketentuan terbaru sebelum mengajukan produk.</p></div>
            </div>
            <h3 class="h4 mb-3"><i class="ti ti-wallet me-1 text-primary" aria-hidden="true"></i>Produk simpanan</h3>
            <div class="row g-3 mb-5">
                <?php foreach ($simpanan as $s): ?>
                <div class="col-sm-6 col-lg-3 sk-reveal"><article class="card sk-product mb-0"><div class="card-body"><span class="badge bg-primary-lt mb-2">SIMPANAN</span><h4><?= homeEscape($s['nama_jenis']) ?></h4><p class="text-secondary"><?= homeEscape($s['deskripsi'] ?: 'Informasi dan ketentuan produk tersedia melalui Teller koperasi.') ?></p><div class="small text-secondary">Setoran minimum</div><strong><?= formatRupiah($s['minimum'] ?? 0) ?></strong></div></article></div>
                <?php endforeach; ?>
                <?php if (!$simpanan): ?><p class="text-secondary">Informasi produk simpanan tersedia melalui Teller.</p><?php endif; ?>
            </div>
            <h3 class="h4 mb-3"><i class="ti ti-moneybag me-1 text-primary" aria-hidden="true"></i>Produk pinjaman</h3>
            <div class="row g-3">
                <?php foreach ($pinjaman as $p): ?>
                <div class="col-sm-6 col-lg-3 sk-reveal"><article class="card sk-product mb-0"><div class="card-body"><span class="badge bg-green-lt mb-2">PINJAMAN</span><h4><?= homeEscape($p['nama_jenis']) ?></h4><p class="text-secondary"><?= homeEscape($p['deskripsi'] ?: 'Ajukan kebutuhan pembiayaan Anda melalui Teller koperasi.') ?></p><div class="small text-secondary mb-2"><?= $p['memerlukan_jaminan'] ? 'Dengan jaminan' : 'Tanpa jaminan' ?></div><a href="login.php?role=anggota">Akses akun anggota <i class="ti ti-arrow-right" aria-hidden="true"></i></a></div></article></div>
                <?php endforeach; ?>
                <?php if (!$pinjaman): ?><p class="text-secondary">Informasi produk pinjaman tersedia melalui Teller.</p><?php endif; ?>
            </div>
        </div>
    </section>

    <section class="sk-section alt" id="tentang">
        <div class="container-xl">
            <div class="row g-5 align-items-center">
                <div class="col-lg-5"><div class="card mb-0" style="background:var(--sk-grad);color:#fff;border:0"><div class="card-body p-5 text-center"><span style="width:4rem;height:4rem;display:inline-flex"><?= brandLogo($koperasi['logo'] ?? '') ?></span><div class="mt-3 small" style="letter-spacing:.14em">BERSAMA, LEBIH BERARTI.</div><h2 class="text-white mt-2">Dari anggota,<br>untuk anggota.</h2></div></div></div>
                <div class="col-lg-7"><span class="sk-eyebrow" style="color:var(--tblr-primary)">TENTANG KOPERASI</span><h2><?= homeEscape($nama) ?></h2><p class="text-secondary">Kami membantu anggota mengelola kebutuhan simpanan dan pinjaman melalui pencatatan yang tertata dan layanan yang mudah dijangkau.</p><p class="text-secondary">Anggota dapat melihat informasi melalui akun masing-masing. Untuk pendaftaran, pengajuan, atau bantuan transaksi, silakan menghubungi pengurus atau Teller koperasi.</p><a class="btn btn-outline-primary" href="#kontak">Hubungi koperasi <i class="ti ti-arrow-right ms-1" aria-hidden="true"></i></a></div>
            </div>
        </div>
    </section>

    <section class="sk-contact" id="kontak">
        <div class="container-xl">
            <div class="row g-4 align-items-center">
                <div class="col-lg-6"><span class="sk-eyebrow">KAMI SIAP MEMBANTU</span><h2>Mari bicarakan<br>kebutuhan Anda.</h2><p class="text-white-50 mb-0">Informasi dan layanan lebih lanjut tersedia melalui pengurus koperasi.</p></div>
                <div class="col-lg-6">
                    <?php foreach (['alamat' => ['ti-map-pin', 'Alamat koperasi'], 'telepon' => ['ti-phone', 'Telepon'], 'email' => ['ti-mail', 'Email']] as $key => $meta): ?>
                        <?php if (!empty($koperasi[$key]) && $koperasi[$key] !== '-'): ?>
                        <div class="d-flex align-items-center gap-3 mb-3"><span class="sk-icon-badge" style="background:rgba(255,255,255,.12);color:#fff"><i class="ti <?= $meta[0] ?>" aria-hidden="true"></i></span><span><small><?= $meta[1] ?></small><strong><?= homeEscape($koperasi[$key]) ?></strong></span></div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <a class="btn btn-light mt-2" href="login.php">Masuk ke layanan anggota <i class="ti ti-arrow-right ms-1" aria-hidden="true"></i></a>
                </div>
            </div>
        </div>
    </section>
</main>
<footer class="sk-footer"><div class="container-xl d-flex flex-wrap justify-content-between gap-2"><span>© <?= date('Y') ?> <?= homeEscape($nama) ?></span><a href="#beranda">Kembali ke atas <i class="ti ti-arrow-up" aria-hidden="true"></i></a></div></footer>
<script src="assets/vendor/tabler/tabler.min.js"></script>
<script>
(function () {
    var els = document.querySelectorAll('.sk-reveal');
    if (!('IntersectionObserver' in window)) { els.forEach(function (e) { e.classList.add('in'); }); return; }
    var io = new IntersectionObserver(function (items) { items.forEach(function (i) { if (i.isIntersecting) { i.target.classList.add('in'); io.unobserve(i.target); } }); }, { threshold: .15 });
    els.forEach(function (e, i) { e.style.transitionDelay = (i % 4) * 70 + 'ms'; io.observe(e); });
    document.querySelectorAll('#homeLinks a').forEach(function (a) { a.addEventListener('click', function () { var c = document.getElementById('homeLinks'); if (c.classList.contains('show')) bootstrap.Collapse.getOrCreateInstance(c).hide(); }); });
})();
</script>
</body>
</html>