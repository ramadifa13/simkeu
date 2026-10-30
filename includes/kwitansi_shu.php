<?php
if (PHP_SAPI !== 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/shu_pinjaman_helper.php';

if (!shuPinjamanSiap($db)) {
    http_response_code(404);
    exit('Pembayaran tidak ditemukan.');
}

$s = $db->prepare(
    "SELECT h.*, p.kode_pinjaman, p.tanggal_lunas,
            a.nama_lengkap, a.kode_anggota, a.user_id member_user,
            src.nama_lengkap sumber_nama, u.nama_lengkap admin_nama
     FROM shu_hak_pinjaman h
     JOIN pinjaman p ON p.id = h.pinjaman_id
     JOIN anggota a ON a.id = h.anggota_id
     JOIN anggota src ON src.id = h.sumber_anggota_id
     LEFT JOIN users u ON u.id = h.dibayar_oleh
     WHERE h.id = ? AND h.status_bayar = 'sudah'"
);
$s->execute([(int)($_GET['id'] ?? 0)]);
$r = $s->fetch(PDO::FETCH_ASSOC);

if (!$r || (!isAdmin() && (!isAnggota() || (int)$r['member_user'] !== (int)$_SESSION['user_id']))) {
    http_response_code(404);
    exit('Pembayaran tidak ditemukan.');
}

if (isset($_GET['bukti'])) {
    $base = realpath(__DIR__ . '/../uploads/pembayaran');
    $file = realpath(__DIR__ . '/../uploads/' . $r['bukti']);
    if (!$base || !$file || strpos($file, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($file)) {
        http_response_code(404);
        exit('Bukti tidak ditemukan.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file);
    if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
        http_response_code(404);
        exit;
    }
    header('Content-Type:' . $mime);
    header('Cache-Control:private,no-store');
    header('X-Content-Type-Options:nosniff');
    readfile($file);
    exit;
}

$org = $db->query('SELECT * FROM koperasi WHERE id = 1')->fetch(PDO::FETCH_ASSOC) ?: [];
$nomor = 'SHU-' . str_pad((string)$r['id'], 6, '0', STR_PAD_LEFT);
$title = 'Kwitansi ' . $nomor;
$isRef = ($r['jenis'] ?? '') === 'referensi';
$jenisLabel = $isRef ? 'Bonus referensi' : 'SHU pelunasan pribadi';
$jenisDetail = $isRef
    ? 'Referensi dari ' . ($r['sumber_nama'] ?? '—')
    : 'Pelunasan pinjaman pribadi';
$persen = rtrim(rtrim(number_format((float)$r['persen'], 4, '.', ''), '0'), '.');
$metode = ucfirst((string)($r['metode'] ?? '-'));
$backHref = isAdmin() ? 'shu.php' : 'shu_saya.php';
$hasBukti = !empty($r['bukti']);

$pdfBody = '
<table cellpadding="0" cellspacing="0" width="100%" style="font-family:dejavusans,sans-serif;color:#0f172a;">
<tr><td style="font-size:14px;font-weight:bold;">' . layananEscape($org['nama'] ?? APP_NAME) . '</td></tr>
<tr><td style="font-size:9px;color:#64748b;padding-bottom:8px;">' . layananEscape($org['alamat'] ?? '') . '</td></tr>
<tr><td style="border-top:2px solid #b45309;padding-top:10px;font-size:13px;font-weight:bold;color:#b45309;">KWITANSI PEMBAYARAN SHU</td></tr>
<tr><td style="font-size:9px;color:#64748b;padding-bottom:12px;">No. ' . layananEscape($nomor) . '</td></tr>
</table>
<table cellpadding="6" cellspacing="0" width="100%" style="font-family:dejavusans,sans-serif;font-size:10px;border:1px solid #e2e8f0;">
<tr bgcolor="#fffbeb"><td width="38%" style="color:#64748b;">Penerima</td><td><strong>' . layananEscape($r['nama_lengkap']) . '</strong><br><span style="color:#64748b;">' . layananEscape($r['kode_anggota']) . '</span></td></tr>
<tr><td style="color:#64748b;">Pinjaman sumber</td><td>' . layananEscape($r['kode_pinjaman']) . (!empty($r['tanggal_lunas']) ? ' · Lunas ' . layananEscape(formatTanggalUi($r['tanggal_lunas'])) : '') . '</td></tr>
<tr bgcolor="#f8fafc"><td style="color:#64748b;">Jenis hak</td><td>' . layananEscape($jenisLabel) . '<br><span style="color:#64748b;">' . layananEscape($jenisDetail) . '</span></td></tr>
<tr><td style="color:#64748b;">Perhitungan</td><td>' . formatRupiah($r['dasar_pinjaman']) . ' × ' . layananEscape($persen) . '%</td></tr>
<tr bgcolor="#fffbeb"><td style="color:#64748b;">Jumlah diterima</td><td style="font-size:14px;font-weight:bold;color:#b45309;">' . formatRupiah($r['jumlah']) . '</td></tr>
<tr><td style="color:#64748b;">Metode / referensi</td><td>' . layananEscape($metode . ' / ' . ($r['referensi'] ?? '-')) . '</td></tr>
<tr bgcolor="#f8fafc"><td style="color:#64748b;">Tanggal bayar</td><td>' . layananEscape(!empty($r['dibayar_at']) ? formatTanggalUi($r['dibayar_at'], true) : '—') . '</td></tr>
<tr><td style="color:#64748b;">Dicatat oleh</td><td>' . layananEscape($r['admin_nama'] ?? '—') . '</td></tr>
</table>
<p style="font-size:8px;color:#94a3b8;margin-top:14px;">Dokumen ini sah sebagai bukti pembayaran SHU pinjaman. Dicetak ' . layananEscape(formatTanggalUi(date('Y-m-d H:i:s'), true)) . '.</p>
';

if (isset($_GET['pdf'])) {
    require_once __DIR__ . '/../tcpdf/tcpdf.php';
    $pdf = new TCPDF('P', 'mm', 'A5', true, 'UTF-8', false);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(12, 12, 12);
    $pdf->SetAutoPageBreak(true, 12);
    $pdf->SetTitle($title);
    $pdf->AddPage();
    $pdf->SetFont('dejavusans', '', 10);
    $pdf->writeHTML($pdfBody, true, false, true, false, '');
    $pdf->Output('Kwitansi-SHU-' . $r['id'] . '.pdf', 'D');
    exit;
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= layananEscape($title) ?></title>
    <style>
        :root {
            --ink: #0f172a;
            --muted: #64748b;
            --line: #e2e8f0;
            --accent: #b45309;
            --accent-soft: #fffbeb;
            --paper: #ffffff;
            --page: #f1f5f9;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; padding: 24px 16px;
            font-family: "Segoe UI", Tahoma, sans-serif;
            background: var(--page); color: var(--ink);
        }
        .toolbar {
            display: flex; flex-wrap: wrap; gap: .5rem;
            max-width: 720px; margin: 0 auto 1rem;
        }
        .toolbar a, .toolbar button {
            display: inline-flex; align-items: center; gap: .35rem;
            padding: .55rem .9rem; border-radius: .55rem;
            border: 1px solid #cbd5e1; background: #fff; color: #334155;
            text-decoration: none; font-size: .875rem; font-weight: 600; cursor: pointer;
        }
        .toolbar a:hover, .toolbar button:hover { background: #f8fafc; }
        .toolbar .primary { background: var(--accent); border-color: var(--accent); color: #fff; }
        .sheet {
            max-width: 720px; margin: 0 auto; background: var(--paper);
            border: 1px solid var(--line); border-radius: 1rem;
            box-shadow: 0 10px 30px rgba(15, 23, 42, .06); overflow: hidden;
        }
        .sheet-head {
            display: flex; justify-content: space-between; gap: 1rem; align-items: flex-start;
            padding: 1.35rem 1.5rem 1.1rem; border-bottom: 3px solid var(--accent);
            background: linear-gradient(180deg, #fffbeb 0%, #fff 100%);
        }
        .org-name { margin: 0; font-size: 1.15rem; font-weight: 800; color: var(--ink); }
        .org-meta { margin: .25rem 0 0; font-size: .8rem; color: var(--muted); line-height: 1.45; }
        .doc-badge {
            text-align: right; flex-shrink: 0;
        }
        .doc-badge strong {
            display: block; font-size: .72rem; letter-spacing: .08em;
            text-transform: uppercase; color: var(--accent);
        }
        .doc-badge span { font-size: .85rem; font-weight: 700; color: var(--ink); }
        .sheet-title {
            padding: 1rem 1.5rem .25rem;
        }
        .sheet-title h1 {
            margin: 0; font-size: 1.35rem; font-weight: 800; color: var(--accent);
        }
        .sheet-title p { margin: .25rem 0 0; color: var(--muted); font-size: .85rem; }
        .amount-box {
            margin: 1rem 1.5rem;
            padding: 1rem 1.15rem;
            border-radius: .85rem;
            background: var(--accent-soft);
            border: 1px solid #fde68a;
            display: flex; justify-content: space-between; align-items: center; gap: 1rem;
        }
        .amount-box span { display: block; font-size: .72rem; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: #92400e; }
        .amount-box strong { font-size: 1.55rem; font-weight: 800; color: var(--accent); font-variant-numeric: tabular-nums; }
        .rows { padding: 0 1.5rem 1.25rem; }
        .row {
            display: grid; grid-template-columns: 11rem 1fr;
            gap: .75rem; padding: .7rem 0;
            border-bottom: 1px solid var(--line); font-size: .92rem;
        }
        .row:last-child { border-bottom: 0; }
        .row dt { margin: 0; color: var(--muted); font-weight: 650; }
        .row dd { margin: 0; font-weight: 600; }
        .row dd small { display: block; margin-top: .15rem; font-weight: 500; color: var(--muted); }
        .bukti {
            margin: 0 1.5rem 1.5rem; padding: 1rem;
            border: 1px dashed #cbd5e1; border-radius: .75rem; background: #f8fafc;
        }
        .bukti summary {
            cursor: pointer; font-weight: 700; color: #334155; list-style: none;
        }
        .bukti summary::-webkit-details-marker { display: none; }
        .bukti img {
            display: block; max-width: 100%; max-height: 420px; margin-top: .85rem;
            border-radius: .55rem; border: 1px solid var(--line);
        }
        .sheet-foot {
            padding: .9rem 1.5rem 1.25rem;
            border-top: 1px solid var(--line);
            font-size: .75rem; color: var(--muted);
        }
        @media (max-width: 575.98px) {
            .row { grid-template-columns: 1fr; gap: .2rem; }
            .sheet-head { flex-direction: column; }
            .doc-badge { text-align: left; }
            .amount-box { flex-direction: column; align-items: flex-start; }
        }
        @media print {
            body { background: #fff; padding: 0; }
            .toolbar, .bukti summary { display: none !important; }
            .bukti[open] img { display: block; }
            .sheet { box-shadow: none; border: 0; border-radius: 0; max-width: none; }
        }
    </style>
</head>
<body>
    <nav class="toolbar" aria-label="Aksi kwitansi">
        <a href="<?= layananEscape($backHref) ?>">← Kembali</a>
        <button type="button" onclick="window.print()">Cetak</button>
        <a class="primary" href="?id=<?= (int)$r['id'] ?>&amp;pdf=1">Download PDF</a>
    </nav>

    <main class="sheet">
        <header class="sheet-head">
            <div>
                <h2 class="org-name"><?= layananEscape($org['nama'] ?? APP_NAME) ?></h2>
                <p class="org-meta">
                    <?= layananEscape($org['alamat'] ?? '') ?>
                    <?php if (!empty($org['telepon'])): ?><br>Telp. <?= layananEscape($org['telepon']) ?><?php endif; ?>
                    <?php if (!empty($org['email'])): ?> · <?= layananEscape($org['email']) ?><?php endif; ?>
                </p>
            </div>
            <div class="doc-badge">
                <strong>No. Kwitansi</strong>
                <span><?= layananEscape($nomor) ?></span>
            </div>
        </header>

        <div class="sheet-title">
            <h1>Kwitansi Pembayaran SHU</h1>
            <p>Bukti penyerahan hak SHU pinjaman tanpa jaminan</p>
        </div>

        <div class="amount-box">
            <div>
                <span>Jumlah diterima</span>
                <strong><?= formatRupiah($r['jumlah']) ?></strong>
            </div>
            <div style="text-align:right">
                <span>Status</span>
                <strong style="font-size:1rem;color:#15803d;">Lunas dibayar</strong>
            </div>
        </div>

        <dl class="rows">
            <div class="row">
                <dt>Penerima</dt>
                <dd>
                    <?= layananEscape($r['nama_lengkap']) ?>
                    <small><?= layananEscape($r['kode_anggota']) ?></small>
                </dd>
            </div>
            <div class="row">
                <dt>Pinjaman sumber</dt>
                <dd>
                    <?= layananEscape($r['kode_pinjaman']) ?>
                    <?php if (!empty($r['tanggal_lunas'])): ?>
                    <small>Lunas <?= layananEscape(formatTanggalUi($r['tanggal_lunas'])) ?></small>
                    <?php endif; ?>
                </dd>
            </div>
            <div class="row">
                <dt>Jenis hak</dt>
                <dd>
                    <?= layananEscape($jenisLabel) ?>
                    <small><?= layananEscape($jenisDetail) ?></small>
                </dd>
            </div>
            <div class="row">
                <dt>Perhitungan</dt>
                <dd><?= formatRupiah($r['dasar_pinjaman']) ?> × <?= layananEscape($persen) ?>%</dd>
            </div>
            <div class="row">
                <dt>Metode</dt>
                <dd><?= layananEscape($metode) ?></dd>
            </div>
            <div class="row">
                <dt>Referensi</dt>
                <dd><?= layananEscape($r['referensi'] ?? '—') ?></dd>
            </div>
            <div class="row">
                <dt>Tanggal bayar</dt>
                <dd><?= layananEscape(!empty($r['dibayar_at']) ? formatTanggalUi($r['dibayar_at'], true) : '—') ?></dd>
            </div>
            <div class="row">
                <dt>Dicatat oleh</dt>
                <dd><?= layananEscape($r['admin_nama'] ?? '—') ?></dd>
            </div>
        </dl>

        <?php if ($hasBukti): ?>
        <details class="bukti">
            <summary>Lihat foto bukti pembayaran</summary>
            <img src="?id=<?= (int)$r['id'] ?>&amp;bukti=1" alt="Bukti pembayaran SHU">
        </details>
        <?php endif; ?>

        <footer class="sheet-foot">
            Dokumen ini sah sebagai bukti pembayaran SHU pinjaman.
            Dicetak <?= layananEscape(formatTanggalUi(date('Y-m-d H:i:s'), true)) ?>.
        </footer>
    </main>
</body>
</html>
