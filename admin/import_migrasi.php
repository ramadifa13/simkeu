<?php
require_once '../includes/auth.php';
require_once '../config/config.php';
require_once '../includes/migrasi_excel_helper.php';
require_once '../includes/migrasi_import_service.php';
require_once '../includes/dokumen_anggota.php';
require_once '../includes/layanan_helper.php';
if (!isAdmin()) {
    $_SESSION['error'] = 'Hanya Admin yang dapat mengakses import migrasi.';
    header('Location: dashboard.php');
    exit();
}
$page_title = 'Import Migrasi';
$hasil = null;
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
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Gagal mengunggah file. Kode error: ' . (int)$file['error']);
        }
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
            if (!empty($hasil['errors'])) {
                throw new RuntimeException('Import final dibatalkan karena masih ada error validasi.');
            }
            $import = migrasiImportFinal($db, $file['tmp_name'], $_SESSION['user_id'] ?? null);
            $dendaDihitung = 0;
            if (function_exists('perbaruiDendaOtomatis')) {
                $dendaDihitung = (int)perbaruiDendaOtomatis($db, true);
            }
            if (function_exists('layananPastikanPengaturan')) {
                layananPastikanPengaturan($db);
            }
            $dokumenHilang = 0;
            if (function_exists('dokumenAnggotaAdaDiDisk')) {
                $cekFoto = $db->query('SELECT foto_ktp, foto_kk, foto_ktp_pasangan FROM anggota');
                while ($fr = $cekFoto->fetch(PDO::FETCH_ASSOC)) {
                    foreach (['foto_ktp', 'foto_kk', 'foto_ktp_pasangan'] as $fc) {
                        $v = trim((string)($fr[$fc] ?? ''));
                        if ($v !== '' && !dokumenAnggotaAdaDiDisk($v)) {
                            $dokumenHilang++;
                        }
                    }
                }
            }
            $_SESSION['success'] = 'Import final berhasil. '
                . (int)($import['koperasi'] ?? 0) . ' konfigurasi, '
                . (int)$import['anggota'] . ' nasabah, '
                . (int)$import['pinjaman'] . ' pinjaman, dan '
                . (int)$import['pembayaran'] . ' pembayaran diproses. '
                . 'Dokumen dipulihkan: ' . (int)($import['dokumen'] ?? 0) . ' (foto/TTD dari cadangan + Excel). '
                . ($dokumenHilang > 0
                    ? "Peringatan: $dokumenHilang path foto di DB tanpa file di uploads/ — unggah ulang atau restore folder uploads. "
                    : 'Semua path foto yang tersimpan terhubung ke file di disk. ')
                . 'Denda otomatis dihitung ulang: ' . $dendaDihitung . ' cicilan. '
                . 'Notifikasi role (pengajuan/denda/jatuh tempo) mengikuti data baru. '
                . 'Import ulang file yang sama tidak akan menggandakan transaksi.';
            header('Location: import_migrasi.php');
            exit;
        }
    } catch (Throwable $e) {
        $_SESSION['error'] = $e->getMessage();
    }
}

$summaryLabels = [
    'pencairan' => 'Total pencairan',
    'tagihan_nasabah' => 'Total tagihan nasabah',
    'bunga_kotor' => 'Proyeksi bunga kotor',
    'angsuran_diterima' => 'Angsuran historis diterima',
    'modal_dapin' => 'Modal Dapin',
    'wajib_dapin' => 'Total wajib kembali ke Dapin',
    'modal_dapin_dialokasikan' => 'Modal Dapin dialokasikan',
    'wajib_dapin_dialokasikan' => 'Kewajiban Dapin dialokasikan',
    'laba_bersih_proyeksi' => 'Proyeksi laba bersih',
];
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Import Migrasi - <?= htmlspecialchars(APP_NAME) ?></title>
    <?php $uiAssetPrefix = '../'; include '../includes/layout/assets.php'; ?>
</head>
<body>
<?php include __DIR__ . '/../includes/layout/shell_start.php'; ?>
            <div class="ui-tx-page">
                <header class="ui-dash-header">
                    <div class="ui-dash-header-icon ui-tx-icon-import" aria-hidden="true"><i class="ti ti-file-import"></i></div>
                    <div class="ui-dash-header-copy">
                        <p class="ui-dash-eyebrow mb-0">Pengaturan</p>
                        <h1 class="ui-dash-title">Import Migrasi</h1>
                        <p class="ui-dash-meta mb-0">Validasi Excel historis sebelum mengubah database</p>
                    </div>
                    <div class="ui-dash-header-actions">
                        <a class="btn btn-sm ui-set-btn-ghost is-template" href="download_template_migrasi.php">
                            <i class="ti ti-download me-1" aria-hidden="true"></i><span class="d-none d-sm-inline">Template</span>
                        </a>
                        <a class="btn btn-sm ui-set-btn-ghost is-backup" href="backup.php">
                            <i class="ti ti-database-export me-1" aria-hidden="true"></i><span class="d-none d-sm-inline">Backup</span>
                        </a>
                    </div>
                </header>

                <?php ui_flash(); ?>

                <div class="ui-set-notes mb-3">
                    <article class="ui-set-note is-info">
                        <strong>Pratinjau aman</strong>
                        <p>Tidak mengubah data. Import Final hanya muncul jika validasi lolos. Setelah import, pinjaman aktif langsung bisa diproses di Angsuran / Layanan.</p>
                    </article>
                    <article class="ui-set-note">
                        <strong>Foto &amp; TTD</strong>
                        <p>Reset Total mencadangkan dokumen. Import dengan kode sama memulihkan otomatis. File tetap di <code>uploads/</code>. Pindah hosting: pakai Backup ZIP.</p>
                    </article>
                </div>

                <div class="card ui-set-card mb-3">
                    <div class="card-header ui-dash-sec">
                        <span class="ui-dash-sec-title"><i class="ti ti-upload" aria-hidden="true"></i>Unggah file migrasi</span>
                        <span class="ui-dash-sec-note">.xlsx · maks <?= (int)$batasMbMigrasi ?> MB</span>
                    </div>
                    <div class="card-body">
                        <form method="post" enctype="multipart/form-data" id="formPratinjauMigrasi">
                            <?= layananToken() ?>
                            <div class="ui-import-zone">
                                <label class="form-label" for="file_migrasi">
                                    <i class="ti ti-file-spreadsheet text-success me-1" aria-hidden="true"></i>File Excel migrasi
                                </label>
                                <input id="file_migrasi" class="form-control" type="file" name="file_migrasi"
                                       accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
                                <div class="form-text">ZIP/backup tidak didukung di sini — gunakan menu Backup untuk itu.</div>
                            </div>
                            <div id="alertFormatMigrasi" class="alert alert-danger d-none mt-3" role="alert"></div>
                            <div class="ui-set-actions mt-3">
                                <button class="btn btn-primary" type="submit">
                                    <i class="ti ti-search me-1" aria-hidden="true"></i>Pratinjau &amp; validasi
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <?php if ($hasil): ?>
                <div class="card ui-set-card mb-3">
                    <div class="card-header ui-dash-sec">
                        <span class="ui-dash-sec-title"><i class="ti ti-list-check" aria-hidden="true"></i>Hasil pratinjau</span>
                        <span class="ui-dash-sec-note"><?= (int)array_sum($hasil['counts']) ?> baris dibaca</span>
                    </div>
                    <div class="card-body">
                        <div class="ui-import-counts">
                            <?php foreach ($hasil['counts'] as $name => $count): ?>
                            <div class="ui-import-count">
                                <span><?= htmlspecialchars((string)$name) ?></span>
                                <strong><?= (int)$count ?></strong>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <h3 class="h6 mt-4 mb-2">Ringkasan keuangan</h3>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover align-middle mb-0 ui-import-summary">
                                <tbody>
                                <?php foreach ($summaryLabels as $key => $label): ?>
                                    <tr>
                                        <th><?= htmlspecialchars($label) ?></th>
                                        <td class="text-end fw-semibold"><?= formatRupiah($hasil['summary'][$key] ?? 0) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php if (!empty($hasil['warnings'])): ?>
                        <div class="alert alert-info mt-3 mb-0">
                            <strong>Perlu dikonfirmasi:</strong>
                            <ul class="mb-0 mt-1">
                                <?php foreach ($hasil['warnings'] as $warning): ?>
                                <li><?= htmlspecialchars($warning) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($hasil['errors'])): ?>
                        <div class="alert alert-warning mt-3 mb-0">
                            <strong>Perlu diperbaiki sebelum import final:</strong>
                            <ul class="mb-0 mt-1">
                                <?php foreach ($hasil['errors'] as $error): ?>
                                <li><?= htmlspecialchars($error) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <?php else: ?>
                        <div class="alert alert-success mt-3">Validasi dasar lolos. Periksa ringkasan sebelum melanjutkan.</div>
                        <form method="post" enctype="multipart/form-data" class="ui-import-final"
                              onsubmit="var f=this.file_migrasi.files[0]; if(!f||!/\.xlsx$/i.test(f.name)||f.size>10*1024*1024){alert(<?= json_encode($pesanFormatMigrasi, JSON_UNESCAPED_UNICODE) ?>); return false;} return confirm('Import final akan mengubah data database. Pastikan backup sudah dibuat. Lanjutkan?')">
                            <?= layananToken() ?>
                            <input type="hidden" name="import_final" value="1">
                            <label class="form-label" for="file_migrasi_final">Unggah ulang file yang sama untuk import final</label>
                            <div class="d-flex flex-wrap gap-2 align-items-center">
                                <input id="file_migrasi_final" class="form-control" style="max-width:28rem" type="file" name="file_migrasi"
                                       accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
                                <button class="btn btn-success" type="submit">
                                    <i class="ti ti-database me-1" aria-hidden="true"></i>Import final
                                </button>
                            </div>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
<?php include __DIR__ . '/../includes/layout/shell_end.php'; ?>
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
</body>
</html>
