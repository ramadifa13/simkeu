<?php
require_once '../includes/auth.php';
require_once '../config/config.php';
require_once '../includes/migrasi_excel_helper.php';
require_once '../includes/migrasi_import_service.php';
if (!isAdmin()) { $_SESSION['error']='Hanya Admin yang dapat mengakses import migrasi.'; header('Location: dashboard.php'); exit(); }
$page_title = 'Import Migrasi'; $hasil = null;
$batasMbMigrasi = 10;
$pesanFormatMigrasi = 'Format tidak sesuai. Import migrasi hanya menerima file Excel (.xlsx) maksimal '
    . $batasMbMigrasi . ' MB. File ZIP, XLS, CSV, atau backup tidak didukung di halaman ini.';

// Upload > post_max_size mengosongkan $_POST/$_FILES → token gagal ("formulir kedaluwarsa")
if (cekPostMelebihiBatas()) {
    $ukuranMb = round(((int)($_SERVER['CONTENT_LENGTH'] ?? 0)) / 1048576, 1);
    $_SESSION['error'] = $pesanFormatMigrasi
        . ' File yang diunggah (~' . $ukuranMb . ' MB) melebihi batas dan kemungkinan berupa ZIP/backup.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        layananValidasiToken();
        $file = $_FILES['file_migrasi'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            throw new RuntimeException('Pilih file Excel template migrasi (.xlsx) terlebih dahulu.');
        }
        if (($file['error'] ?? 0) === UPLOAD_ERR_INI_SIZE || ($file['error'] ?? 0) === UPLOAD_ERR_FORM_SIZE) {
            throw new RuntimeException($pesanFormatMigrasi . ' Ukuran file melebihi batas server.');
        }
        if ($file['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Gagal mengunggah file. Kode error: ' . (int)$file['error']);
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'xlsx') {
            throw new RuntimeException($pesanFormatMigrasi . ' File dipilih: .' . ($ext !== '' ? $ext : '(tanpa ekstensi)') . '.');
        }
        if ($file['size'] > $batasMbMigrasi * 1024 * 1024) {
            throw new RuntimeException($pesanFormatMigrasi . ' Ukuran file saat ini: '
                . round($file['size'] / 1048576, 1) . ' MB.');
        }
        $hasil = migrasiRingkasanXlsx($file['tmp_name']);
        if (isset($_POST['import_final'])) {
            if (!empty($hasil['errors'])) throw new RuntimeException('Import final dibatalkan karena masih ada error validasi.');
            $import = migrasiImportFinal($db, $file['tmp_name'], $_SESSION['user_id'] ?? null);
            $dendaDihitung = 0;
            if (function_exists('perbaruiDendaOtomatis')) {
                $dendaDihitung = (int) perbaruiDendaOtomatis($db, true);
            }
            $_SESSION['success'] = 'Import final berhasil. '
                . (int)($import['koperasi'] ?? 0) . ' konfigurasi, '
                . (int)$import['anggota'] . ' nasabah, '
                . (int)$import['pinjaman'] . ' pinjaman, dan '
                . (int)$import['pembayaran'] . ' pembayaran diproses. '
                . 'Dokumen dipulihkan: ' . (int)($import['dokumen'] ?? 0) . ' (foto/TTD dari cadangan + Excel). '
                . 'Denda otomatis dihitung ulang: ' . $dendaDihitung . ' cicilan. '
                . 'Import ulang file yang sama tidak akan menggandakan transaksi.';
            header('Location: import_migrasi.php'); exit;
        }
    } catch (Throwable $e) { $_SESSION['error'] = $e->getMessage(); }
}
?>
<!doctype html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Import Migrasi - <?= htmlspecialchars(APP_NAME) ?></title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet"><link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet"><style>
.migrasi-shell{max-width:1240px}.migrasi-hero{background:linear-gradient(135deg,#17365d,#2376a4);color:#fff;border-radius:16px}.upload-zone{border:2px dashed #8bb5cf;border-radius:12px;background:#f5fbff}.stat-box{height:100%;border:1px solid #dce7ef;border-radius:10px;background:#fff}.stat-box .h4{color:#17365d}.summary-table th{width:62%;background:#f7fafc}.step{display:flex;gap:.75rem;align-items:flex-start}.step-number{background:#17365d;color:#fff;border-radius:50%;width:28px;height:28px;display:grid;place-items:center;font-weight:700;flex:none}
</style></head>
<body class="bg-light"><?php include '../includes/header.php'; ?><div class="container-fluid"><div class="row"><?php include '../includes/sidebar.php'; ?>
<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4"><div class="migrasi-shell"><section class="migrasi-hero p-4 mb-4"><div class="d-flex justify-content-between align-items-start gap-3"><div><div class="text-uppercase small opacity-75 mb-1">Migrasi data historis</div><h2 class="h3 mb-2"><i class="fas fa-file-import me-2"></i>Import & Validasi</h2><p class="mb-0 opacity-75">Periksa kelengkapan data dan proyeksi kas sebelum data direset atau diimport.</p></div><span class="badge bg-light text-primary p-2">Validasi wajib</span></div></section>
<div class="alert alert-info border-0 shadow-sm"><i class="fas fa-shield-alt me-2"></i><strong>Pratinjau tidak mengubah data.</strong> Tombol Import Final hanya tersedia saat seluruh validasi lolos.</div>
<div class="alert alert-secondary border-0 shadow-sm"><i class="fas fa-camera me-2"></i><strong>Foto &amp; TTD tidak hilang:</strong> sebelum Reset Total sistem mencadangkan foto KTP/KK/pasangan, foto pencairan, foto jaminan, dan TTD perjanjian. Saat Import Final dengan <em>kode anggota/pinjaman yang sama</em>, dokumen dipulihkan otomatis (meski kolom Excel kosong). File fisik tetap di <code>uploads/</code>. Untuk pindah hosting: pakai Backup ZIP lengkap lalu ekstrak uploads. TTD Admin juga dipertahankan.</div>
<?php if (!empty($_SESSION['error'])): ?><div class="alert alert-danger"><?= htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div><?php endif; ?>
<div class="card shadow-sm border-0"><div class="card-body p-4"><div class="d-flex flex-wrap justify-content-between align-items-start gap-2"><div><h5 class="mb-1">Unggah file migrasi</h5><p class="text-muted small mb-3">Hanya file Excel template migrasi (<strong>.xlsx</strong>), maksimal 10 MB. ZIP/backup tidak didukung di sini.</p></div><a class="btn btn-outline-success" href="download_template_migrasi.php"><i class="fas fa-download me-1"></i>Download Template Migrasi</a></div><form method="post" enctype="multipart/form-data" id="formPratinjauMigrasi"><?= layananToken() ?><div class="upload-zone p-3 mb-3"><label for="file_migrasi" class="form-label fw-semibold"><i class="fas fa-file-excel text-success me-1"></i>File Excel migrasi (.xlsx)</label><input id="file_migrasi" class="form-control" type="file" name="file_migrasi" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required><div class="form-text">Untuk pindah hosting (uploads/foto), gunakan menu Backup — bukan halaman ini.</div></div><div id="alertFormatMigrasi" class="alert alert-danger d-none" role="alert"></div><button class="btn btn-primary px-4" type="submit"><i class="fas fa-search me-1"></i>Pratinjau & Validasi</button></form></div></div>
<?php if ($hasil): ?><div class="card shadow-sm border-0 mt-4"><div class="card-body p-4"><div class="d-flex justify-content-between align-items-center mb-3"><h5 class="mb-0">Hasil Pratinjau</h5><span class="badge bg-primary"><?= array_sum($hasil['counts']) ?> baris dibaca</span></div><div class="row g-3"><?php foreach ($hasil['counts'] as $name=>$count): ?><div class="col-6 col-md-4 col-xl-3"><div class="stat-box p-3"><small class="text-muted d-block text-truncate"><?= htmlspecialchars($name) ?></small><div class="h4 mb-0 mt-1"><?= (int)$count ?></div></div></div><?php endforeach; ?></div>
<h5 class="mt-4">Ringkasan Keuangan</h5><div class="table-responsive"><table class="table table-sm table-bordered summary-table"><tbody><?php foreach (['pencairan'=>'Total pencairan','tagihan_nasabah'=>'Total tagihan nasabah','bunga_kotor'=>'Proyeksi bunga kotor','angsuran_diterima'=>'Angsuran historis diterima','modal_dapin'=>'Modal Dapin','wajib_dapin'=>'Total wajib kembali ke Dapin','modal_dapin_dialokasikan'=>'Modal Dapin dialokasikan','wajib_dapin_dialokasikan'=>'Kewajiban Dapin dialokasikan','laba_bersih_proyeksi'=>'Proyeksi laba bersih'] as $key=>$label): ?><tr><th><?= htmlspecialchars($label) ?></th><td class="fw-semibold">Rp <?= number_format((float)$hasil['summary'][$key],0,',','.') ?></td></tr><?php endforeach; ?></tbody></table></div>
<?php if (!empty($hasil['warnings'])): ?><div class="alert alert-info mt-3"><strong>Perlu dikonfirmasi:</strong><ul class="mb-0"><?php foreach ($hasil['warnings'] as $warning): ?><li><?= htmlspecialchars($warning) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if ($hasil['errors']): ?><div class="alert alert-warning mt-3"><strong>Perlu diperbaiki sebelum import final:</strong><ul class="mb-0"><?php foreach ($hasil['errors'] as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul></div><?php else: ?><div class="alert alert-success mt-3">Validasi dasar lolos. Periksa kembali ringkasan sebelum melanjutkan.</div><form method="post" enctype="multipart/form-data" class="mt-3" onsubmit="var f=this.file_migrasi.files[0]; if(!f||!/\.xlsx$/i.test(f.name)||f.size>10*1024*1024){alert(<?= json_encode($pesanFormatMigrasi, JSON_UNESCAPED_UNICODE) ?>); return false;} return confirm('Import final akan mengubah data database. Pastikan backup sudah dibuat. Lanjutkan?')"><?= layananToken() ?><input type="hidden" name="import_final" value="1"><input type="file" name="file_migrasi" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required><button class="btn btn-success ms-2"><i class="fas fa-database me-1"></i>Import Final</button></form><?php endif; ?></div></div><?php endif; ?>
</div></main></div></div><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
  var batasByte = <?= (int)$batasMbMigrasi ?> * 1024 * 1024;
  var pesan = <?= json_encode($pesanFormatMigrasi, JSON_UNESCAPED_UNICODE) ?>;
  function validasiFile(input, alertEl) {
    var f = input && input.files && input.files[0];
    if (!f) return true;
    var nama = (f.name || '').toLowerCase();
    var okExt = nama.slice(-5) === '.xlsx';
    if (!okExt || f.size > batasByte) {
      if (alertEl) {
        alertEl.textContent = pesan + (okExt ? '' : ' File dipilih: ' + f.name + '.');
        alertEl.classList.remove('d-none');
      } else {
        alert(pesan);
      }
      input.value = '';
      return false;
    }
    if (alertEl) alertEl.classList.add('d-none');
    return true;
  }
  var input = document.getElementById('file_migrasi');
  var alertEl = document.getElementById('alertFormatMigrasi');
  var form = document.getElementById('formPratinjauMigrasi');
  if (input) input.addEventListener('change', function () { validasiFile(input, alertEl); });
  if (form) form.addEventListener('submit', function (e) {
    if (!validasiFile(input, alertEl)) e.preventDefault();
  });
})();
</script>
</body></html>
