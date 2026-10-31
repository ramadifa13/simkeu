<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/brand.php';
if (isLoggedIn()) {
    redirectBasedOnRole();
}
$koperasi = $db->query('SELECT * FROM koperasi WHERE id=1')->fetch(PDO::FETCH_ASSOC) ?: ['nama' => APP_NAME];
$nama = $koperasi['nama'] ?? APP_NAME;
$simpanan = $db->query(
    "SELECT nama_jenis, deskripsi, minimum FROM jenis_simpanan
     WHERE status='aktif' AND kode_jenis NOT IN ('ANG','BONUS','SHU')
     ORDER BY id LIMIT 4"
)->fetchAll(PDO::FETCH_ASSOC);
$pinjamanRows = $db->query(
    "SELECT nama_jenis, deskripsi, memerlukan_jaminan, frekuensi_diizinkan, tenor_max
     FROM jenis_pinjaman
     WHERE status='aktif'
     ORDER BY memerlukan_jaminan ASC,
              FIELD(SUBSTRING_INDEX(frekuensi_diizinkan, ',', 1), 'mingguan', 'dua_mingguan', 'bulanan'),
              tenor_max, id"
)->fetchAll(PDO::FETCH_ASSOC);

/** Ringkas produk pinjaman per kategori publik (mingguan / 2 minggu / gadai). */
$pinjaman = [];
foreach ($pinjamanRows as $row) {
    $isGadai = !empty($row['memerlukan_jaminan']);
    $freqParts = array_values(array_filter(array_map('trim', explode(',', (string) ($row['frekuensi_diizinkan'] ?? 'bulanan')))));
    $freq = $freqParts[0] ?? 'bulanan';
    if ($isGadai) {
        $key = 'gadai';
        $title = 'Jaminan / Gadai';
        $meta = 'Dengan jaminan · Tenor bulanan';
        $deskripsi = 'Pinjaman dengan jaminan/gadai. Cicilan tenor bulanan sesuai ketentuan koperasi.';
    } elseif (in_array('dua_mingguan', $freqParts, true) || $freq === 'dua_mingguan') {
        $key = 'dua_mingguan';
        $title = '2 Minggu Sekali';
        $meta = 'Tanpa jaminan';
        $deskripsi = 'Pinjaman khusus tanpa jaminan dengan cicilan setiap 2 minggu sekali.';
    } else {
        $key = 'mingguan';
        $title = 'Mingguan';
        $meta = 'Tanpa jaminan';
        $deskripsi = 'Pinjaman tanpa jaminan dengan cicilan mingguan untuk tenor singkat.';
    }
    if (!isset($pinjaman[$key])) {
        $pinjaman[$key] = [
            'title' => $title,
            'meta' => $meta,
            'deskripsi' => $deskripsi,
            'tenors' => [],
        ];
    }
    $tenor = (int) ($row['tenor_max'] ?? 0);
    if ($tenor > 0) {
        $pinjaman[$key]['tenors'][$tenor] = true;
    }
}
$pinjaman = array_values($pinjaman);
foreach ($pinjaman as &$pItem) {
    $tenors = array_keys($pItem['tenors']);
    sort($tenors, SORT_NUMERIC);
    if ($tenors) {
        $pItem['tenor_label'] = count($tenors) === 1
            ? 'Tenor hingga ' . $tenors[0] . ' bulan'
            : 'Tenor ' . $tenors[0] . '–' . end($tenors) . ' bulan';
    } else {
        $pItem['tenor_label'] = '';
    }
    unset($pItem['tenors']);
}
unset($pItem);

function homeEscape($v)
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}
$cssV = @filemtime(__DIR__ . '/assets/css/public-theme.css') ?: time();
$jsV = @filemtime(__DIR__ . '/assets/js/public-home.js') ?: time();
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Layanan simpanan, pinjaman, dan informasi anggota <?= homeEscape($nama) ?>.">
    <title><?= homeEscape($nama) ?> — Layanan koperasi</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,700;9..144,800&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <?php $uiAssetPrefix = ''; include __DIR__ . '/includes/layout/assets.php'; ?>
    <link rel="stylesheet" href="assets/css/public-theme.css?v=<?= (int) $cssV ?>">
    <script src="assets/js/brand.js?v=20261019-6" defer></script>
    <script src="assets/js/public-home.js?v=<?= (int) $jsV ?>" defer></script>
</head>
<body class="sk-home">
<a class="visually-hidden-focusable" href="#utama">Lewati ke konten</a>

<header class="lp-nav" data-lp-nav>
    <div class="lp-nav-inner">
        <a class="lp-nav-brand" href="index.php">
            <?= brandLogo($koperasi['logo'] ?? '') ?>
            <span><?= homeEscape($nama) ?></span>
        </a>
        <button class="lp-nav-toggle" type="button" data-lp-toggle aria-controls="lpNavMenu" aria-expanded="false" aria-label="Buka menu">
            <i class="ti ti-menu-2" aria-hidden="true"></i>
        </button>
        <nav class="lp-nav-menu" id="lpNavMenu" data-lp-menu aria-label="Navigasi utama">
            <a href="#layanan">Layanan</a>
            <a href="#produk">Produk</a>
            <a href="#kontak">Kontak</a>
            <a class="lp-nav-cta" href="login.php">Masuk</a>
        </nav>
    </div>
</header>

<main id="utama">
    <section class="lp-hero" id="beranda" aria-label="Beranda">
        <div class="lp-hero-visual" aria-hidden="true">
            <div class="lp-hero-glow lp-hero-glow-a"></div>
            <div class="lp-hero-glow lp-hero-glow-b"></div>
            <svg class="lp-hero-art" viewBox="0 0 900 700" fill="none" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="xMidYMid slice">
                <circle cx="720" cy="180" r="140" stroke="rgba(255,255,255,.12)" stroke-width="2"/>
                <circle cx="720" cy="180" r="90" stroke="rgba(255,255,255,.18)" stroke-width="2"/>
                <circle cx="160" cy="520" r="120" stroke="rgba(255,255,255,.1)" stroke-width="2"/>
                <path d="M80 420c80-40 140-20 200 20s140 50 220 10 160-40 240 10" stroke="rgba(255,255,255,.16)" stroke-width="2" stroke-linecap="round"/>
                <path d="M120 480c70-30 130-10 180 18s120 40 190 8 140-28 210 12" stroke="rgba(167,243,208,.28)" stroke-width="2.5" stroke-linecap="round"/>
                <rect x="520" y="360" width="220" height="140" rx="28" fill="rgba(255,255,255,.08)" stroke="rgba(255,255,255,.16)"/>
                <rect x="545" y="395" width="70" height="12" rx="6" fill="rgba(167,243,208,.55)"/>
                <rect x="545" y="420" width="120" height="10" rx="5" fill="rgba(255,255,255,.28)"/>
                <rect x="545" y="445" width="95" height="10" rx="5" fill="rgba(255,255,255,.18)"/>
                <circle cx="700" cy="430" r="28" fill="rgba(45,212,191,.35)"/>
                <path d="M688 430h24M700 418v24" stroke="#ecfdf5" stroke-width="3" stroke-linecap="round"/>
            </svg>
        </div>
        <div class="lp-hero-content">
            <p class="lp-hero-brand lp-anim" data-lp-anim><?= homeEscape($nama) ?></p>
            <h1 class="lp-hero-title lp-anim" data-lp-anim>Keuangan anggota,<br>lebih tertata.</h1>
            <p class="lp-hero-lead lp-anim" data-lp-anim>Simpan, pinjam, dan pantau angsuran dalam satu layanan koperasi yang mudah diakses.</p>
            <div class="lp-hero-actions lp-anim" data-lp-anim>
                <a class="lp-btn lp-btn-primary" href="login.php">Masuk ke akun</a>
                <a class="lp-btn lp-btn-ghost" href="#layanan">Jelajahi layanan</a>
            </div>
        </div>
    </section>

    <section class="lp-section" id="layanan">
        <div class="lp-wrap">
            <header class="lp-section-head lp-reveal">
                <h2>Layanan untuk anggota</h2>
                <p>Semua kebutuhan keuangan koperasi dalam alur yang jelas, sesuai hak akses Anda.</p>
            </header>
            <div class="lp-service-grid">
                <?php
                $layanan = [
                    ['ti-pig-money', 'Simpanan', 'Pantau saldo dan riwayat setoran maupun penarikan.'],
                    ['ti-cash', 'Pinjaman', 'Ikuti status pengajuan, pencairan, dan jadwal angsuran.'],
                    ['ti-building-store', 'Waserda', 'Lihat catatan belanja di warung koperasi.'],
                    ['ti-coins', 'SHU', 'Akses hak SHU setelah pelunasan disahkan pengurus.'],
                ];
                foreach ($layanan as $item):
                ?>
                <a class="lp-service lp-reveal" href="login.php">
                    <span class="lp-service-ico" aria-hidden="true"><i class="ti <?= $item[0] ?>"></i></span>
                    <strong><?= homeEscape($item[1]) ?></strong>
                    <span><?= homeEscape($item[2]) ?></span>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="lp-section lp-section-soft" id="produk">
        <div class="lp-wrap">
            <header class="lp-section-head lp-reveal">
                <h2>Produk koperasi</h2>
                <p>Kenali pilihan simpanan dan pinjaman. Syarat lengkap tersedia melalui Teller.</p>
            </header>

            <h3 class="lp-subhead lp-reveal">Simpanan</h3>
            <div class="lp-product-grid">
                <?php if ($simpanan): foreach ($simpanan as $s): ?>
                <article class="lp-product lp-reveal">
                    <strong><?= homeEscape($s['nama_jenis']) ?></strong>
                    <p><?= homeEscape($s['deskripsi'] ?: 'Ketentuan produk tersedia melalui Teller koperasi.') ?></p>
                    <span class="lp-product-meta">Minimum <?= formatRupiah($s['minimum'] ?? 0) ?></span>
                </article>
                <?php endforeach; else: ?>
                <p class="lp-empty lp-reveal">Informasi produk simpanan tersedia melalui Teller.</p>
                <?php endif; ?>
            </div>

            <h3 class="lp-subhead lp-reveal">Pinjaman</h3>
            <p class="lp-product-lead lp-reveal">Tersedia pinjaman mingguan dan 2 minggu sekali khusus tanpa jaminan, serta pinjaman dengan jaminan/gadai ber-tenor bulanan.</p>
            <div class="lp-product-grid lp-product-grid-pinjaman">
                <?php if ($pinjaman): foreach ($pinjaman as $p): ?>
                <article class="lp-product lp-reveal">
                    <strong><?= homeEscape($p['title']) ?></strong>
                    <p><?= homeEscape($p['deskripsi'] ?: 'Ajukan pembiayaan melalui Teller koperasi.') ?></p>
                    <?php if ($p['tenor_label'] !== ''): ?>
                    <span class="lp-product-tenor"><?= homeEscape($p['tenor_label']) ?></span>
                    <?php endif; ?>
                    <span class="lp-product-meta"><?= homeEscape($p['meta']) ?></span>
                </article>
                <?php endforeach; else: ?>
                <p class="lp-empty lp-reveal">Informasi produk pinjaman tersedia melalui Teller.</p>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <section class="lp-contact" id="kontak">
        <div class="lp-wrap lp-contact-grid">
            <div class="lp-reveal">
                <h2>Hubungi koperasi</h2>
                <p>Butuh bantuan pendaftaran, pengajuan, atau transaksi? Tim pengurus siap membantu.</p>
                <a class="lp-btn lp-btn-light" href="login.php">Masuk ke layanan</a>
            </div>
            <div class="lp-contact-list lp-reveal">
                <?php
                $kontak = [
                    'alamat' => ['ti-map-pin', 'Alamat'],
                    'telepon' => ['ti-phone', 'Telepon'],
                    'email' => ['ti-mail', 'Email'],
                ];
                $adaKontak = false;
                foreach ($kontak as $key => $meta):
                    if (empty($koperasi[$key]) || $koperasi[$key] === '-') {
                        continue;
                    }
                    $adaKontak = true;
                ?>
                <div class="lp-contact-item">
                    <i class="ti <?= $meta[0] ?>" aria-hidden="true"></i>
                    <div>
                        <span><?= $meta[1] ?></span>
                        <strong><?= homeEscape($koperasi[$key]) ?></strong>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php if (!$adaKontak): ?>
                <p class="lp-contact-fallback">Informasi kontak tersedia di kantor koperasi.</p>
                <?php endif; ?>
            </div>
        </div>
    </section>
</main>

<footer class="lp-footer">
    <div class="lp-wrap lp-footer-inner">
        <span>© <?= date('Y') ?> <?= homeEscape($nama) ?></span>
        <a href="#beranda">Kembali ke atas</a>
    </div>
</footer>
</body>
</html>
