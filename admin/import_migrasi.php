<?php
require_once '../includes/auth.php';
require_once '../config/config.php';
require_once '../includes/migrasi_excel_helper.php';
if (!isAdmin()) { $_SESSION['error']='Hanya Admin yang dapat mengakses import migrasi.'; header('Location: dashboard.php'); exit(); }
$page_title = 'Import Migrasi'; $hasil = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        layananValidasiToken();
        $file = $_FILES['file_migrasi'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Pilih file XLSX template migrasi terlebih dahulu.');
        if ($file['size'] > 10 * 1024 * 1024) throw new RuntimeException('Ukuran file maksimal 10 MB.');
        if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'xlsx') throw new RuntimeException('File harus berformat XLSX.');
        $hasil = migrasiRingkasanXlsx($file['tmp_name']);
    } catch (Throwable $e) { $_SESSION['error'] = $e->getMessage(); }
}
?>
<!doctype html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Import Migrasi - <?= htmlspecialchars(APP_NAME) ?></title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet"><link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet"><style>
.migrasi-shell{max-width:1240px}.migrasi-hero{background:linear-gradient(135deg,#17365d,#2376a4);color:#fff;border-radius:16px}.upload-zone{border:2px dashed #8bb5cf;border-radius:12px;background:#f5fbff}.stat-box{height:100%;border:1px solid #dce7ef;border-radius:10px;background:#fff}.stat-box .h4{color:#17365d}.summary-table th{width:62%;background:#f7fafc}.step{display:flex;gap:.75rem;align-items:flex-start}.step-number{background:#17365d;color:#fff;border-radius:50%;width:28px;height:28px;display:grid;place-items:center;font-weight:700;flex:none}
</style></head>
<body class="bg-light"><?php include '../includes/header.php'; ?><div class="container-fluid"><div class="row"><?php include '../includes/sidebar.php'; ?>
<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4"><div class="migrasi-shell"><section class="migrasi-hero p-4 mb-4"><div class="d-flex justify-content-between align-items-start gap-3"><div><div class="text-uppercase small opacity-75 mb-1">Migrasi data historis</div><h2 class="h3 mb-2"><i class="fas fa-file-import me-2"></i>Import & Validasi</h2><p class="mb-0 opacity-75">Periksa kelengkapan data dan proyeksi kas sebelum data direset atau diimport.</p></div><span class="badge bg-light text-primary p-2">Mode aman: pratinjau</span></div></section>
<div class="alert alert-info border-0 shadow-sm"><i class="fas fa-shield-alt me-2"></i><strong>Belum ada data yang diubah.</strong> Halaman ini hanya membaca file dan memberi hasil validasi.</div>
<?php if (!empty($_SESSION['error'])): ?><div class="alert alert-danger"><?= htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div><?php endif; ?>
<div class="card shadow-sm border-0"><div class="card-body p-4"><h5 class="mb-1">Unggah file migrasi</h5><p class="text-muted small mb-3">Gunakan <strong>Template_Migrasi_Reset_Total.xlsx</strong> yang sudah diisi. Maksimal 10 MB.</p><form method="post" enctype="multipart/form-data"><?= layananToken() ?><div class="upload-zone p-3 mb-3"><label for="file_migrasi" class="form-label fw-semibold"><i class="fas fa-file-excel text-success me-1"></i>File Excel migrasi</label><input id="file_migrasi" class="form-control" type="file" name="file_migrasi" accept=".xlsx" required><div class="form-text">File hanya diproses untuk validasi dan tidak disimpan pada tahap ini.</div></div><button class="btn btn-primary px-4"><i class="fas fa-search me-1"></i>Pratinjau & Validasi</button></form></div></div>
<?php if ($hasil): ?><div class="card shadow-sm border-0 mt-4"><div class="card-body p-4"><div class="d-flex justify-content-between align-items-center mb-3"><h5 class="mb-0">Hasil Pratinjau</h5><span class="badge bg-primary"><?= array_sum($hasil['counts']) ?> baris dibaca</span></div><div class="row g-3"><?php foreach ($hasil['counts'] as $name=>$count): ?><div class="col-6 col-md-4 col-xl-3"><div class="stat-box p-3"><small class="text-muted d-block text-truncate"><?= htmlspecialchars($name) ?></small><div class="h4 mb-0 mt-1"><?= (int)$count ?></div></div></div><?php endforeach; ?></div>
<h5 class="mt-4">Ringkasan Keuangan</h5><div class="table-responsive"><table class="table table-sm table-bordered summary-table"><tbody><?php foreach (['pencairan'=>'Total pencairan','tagihan_nasabah'=>'Total tagihan nasabah','bunga_kotor'=>'Proyeksi bunga kotor','angsuran_diterima'=>'Angsuran historis diterima','modal_dapin'=>'Modal Dapin','wajib_dapin'=>'Total wajib kembali ke Dapin','modal_dapin_dialokasikan'=>'Modal Dapin dialokasikan','wajib_dapin_dialokasikan'=>'Kewajiban Dapin dialokasikan','laba_bersih_proyeksi'=>'Proyeksi laba bersih'] as $key=>$label): ?><tr><th><?= htmlspecialchars($label) ?></th><td class="fw-semibold">Rp <?= number_format((float)$hasil['summary'][$key],0,',','.') ?></td></tr><?php endforeach; ?></tbody></table></div>
<?php if (!empty($hasil['warnings'])): ?><div class="alert alert-info mt-3"><strong>Perlu dikonfirmasi:</strong><ul class="mb-0"><?php foreach ($hasil['warnings'] as $warning): ?><li><?= htmlspecialchars($warning) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if ($hasil['errors']): ?><div class="alert alert-warning mt-3"><strong>Perlu diperbaiki sebelum import final:</strong><ul class="mb-0"><?php foreach ($hasil['errors'] as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul></div><?php else: ?><div class="alert alert-success mt-3">Struktur, relasi dasar, dan perhitungan ringkasan valid. Import final belum diaktifkan.</div><?php endif; ?></div></div><?php endif; ?>
</div></main></div></div><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script></body></html>
