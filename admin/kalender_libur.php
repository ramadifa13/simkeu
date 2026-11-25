<?php
require_once __DIR__ . '/../includes/auth.php';
if (!isAdmin()) { http_response_code(403); exit('Khusus Admin.'); }
require_once __DIR__ . '/../includes/pembaruan_helper.php';
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        layananValidasiToken();
        $db->beginTransaction();
        if (isset($_POST['hapus_id'])) {
            $q=$db->prepare('UPDATE kalender_libur_angsuran SET aktif=0 WHERE id=?');
            $q->execute([(int)$_POST['hapus_id']]);
        } else {
            simpanKalenderLibur($db,(string)($_POST['tanggal']??''),(string)($_POST['nama']??''),(string)($_POST['sumber']??'eksternal'),(int)$_SESSION['user_id']);
        }
        $berubah=perbaruiJadwalHariLibur($db);
        $db->commit();
        $_SESSION['success']='Kalender disimpan; '.$berubah.' tanggal angsuran diperbarui.';
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        $_SESSION['error']=$e->getMessage();
    }
    header('Location: kalender_libur.php'); exit;
}
$libur=$db->query('SELECT * FROM kalender_libur_angsuran ORDER BY tanggal DESC')->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Kalender Libur Angsuran</title><?php $uiAssetPrefix='../'; include __DIR__.'/../includes/layout/assets.php'; ?></head><body>
<?php include __DIR__.'/../includes/layout/shell_start.php'; ?>
<div class="ui-tx-page"><header class="ui-dash-header"><div class="ui-dash-header-icon"><i class="ti ti-calendar-off"></i></div><div class="ui-dash-header-copy"><p class="ui-dash-eyebrow mb-0">Peminjaman</p><h1 class="ui-dash-title">Kalender Libur Angsuran</h1><p class="ui-dash-meta mb-0">Kelola Idulfitri, Iduladha dan libur eksternal</p></div></header>
  <div class="alert alert-info">Hanya Admin yang dapat mengelola kalender. Idulfitri/Iduladha dan libur eksternal dicatat manual; tanggal bertabrakan digeser mengikuti frekuensi pinjaman.</div>
  <form method="post" class="card card-body mb-4"><?= layananToken() ?>
    <div class="row g-3"><div class="col-md-3"><label class="form-label">Tanggal</label><input type="date" class="form-control" name="tanggal" required></div>
    <div class="col-md-4"><label class="form-label">Nama libur</label><input class="form-control" name="nama" maxlength="160" required></div>
    <div class="col-md-3"><label class="form-label">Jenis</label><select class="form-select" name="sumber"><option value="idulfitri">Idulfitri</option><option value="iduladha">Iduladha</option><option value="eksternal">Eksternal</option></select></div>
    <div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary w-100">Simpan &amp; hitung ulang</button></div></div>
  </form>
  <div class="card"><div class="table-responsive"><table class="table table-striped mb-0"><thead><tr><th>Tanggal</th><th>Nama</th><th>Jenis</th><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach($libur as $l): ?><tr><td><?= htmlspecialchars($l['tanggal']) ?></td><td><?= htmlspecialchars($l['nama']) ?></td><td><?= htmlspecialchars($l['sumber']) ?></td><td><?= $l['aktif']?'Aktif':'Nonaktif' ?></td><td><?php if($l['aktif']): ?><form method="post"><?= layananToken() ?><input type="hidden" name="hapus_id" value="<?= (int)$l['id'] ?>"><button class="btn btn-sm btn-outline-danger">Nonaktifkan</button></form><?php endif ?></td></tr><?php endforeach ?>
  </tbody></table></div></div>
</div>
<?php include __DIR__.'/../includes/layout/shell_end.php'; ?></body></html>
