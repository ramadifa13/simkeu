<?php
require_once __DIR__ . '/../includes/auth.php';
if (!isAdmin()) {
    http_response_code(403);
    exit('Khusus Admin.');
}
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pesan = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') try {
    layananValidasiToken();
    $aksi = (string)($_POST['aksi'] ?? '');
    $db->beginTransaction();
    if ($aksi === 'buat') {
        $kode = strtoupper(trim((string)($_POST['kode'] ?? '')));
        $nama = trim((string)($_POST['nama'] ?? ''));
        $keterangan = trim((string)($_POST['keterangan'] ?? ''));
        if (!preg_match('/^[A-Z0-9_-]{2,24}$/D', $kode)) throw new InvalidArgumentException('Kode kelompok 2–24 karakter, hanya huruf, angka, garis bawah, atau tanda hubung.');
        if ($nama === '' || mb_strlen($nama) > 120) throw new InvalidArgumentException('Nama kelompok wajib diisi (maksimal 120 karakter).');
        if (mb_strlen($keterangan) > 500) throw new InvalidArgumentException('Keterangan maksimal 500 karakter.');
        $db->prepare("INSERT INTO kelompok_angsuran(kode,nama,keterangan,status,dibuat_oleh) VALUES(?,?,?,'aktif',?)")->execute([$kode, $nama, $keterangan ?: null, (int)$_SESSION['user_id']]);
        $pesan = 'Kelompok angsuran berhasil dibuat.';
    } elseif ($aksi === 'status') {
        $id = (int)($_POST['id'] ?? 0);
        $status = ($_POST['status'] ?? '') === 'aktif' ? 'aktif' : 'nonaktif';
        $q = $db->prepare('SELECT id FROM kelompok_angsuran WHERE id=? FOR UPDATE');
        $q->execute([$id]);
        if (!$q->fetchColumn()) throw new RuntimeException('Kelompok tidak ditemukan.');
        if ($status === 'nonaktif') {
            $q = $db->prepare("SELECT COUNT(*) FROM pengajuan_mutasi_angsuran WHERE kelompok_baru_id=? AND jenis='kelompok' AND status='menunggu'");
            $q->execute([$id]);
            if ((int)$q->fetchColumn()) throw new RuntimeException('Selesaikan permohonan pindah kelompok yang masih menunggu.');
        }
        $db->prepare('UPDATE kelompok_angsuran SET status=? WHERE id=?')->execute([$status, $id]);
        $pesan = 'Status kelompok berhasil diperbarui.';
    } else throw new InvalidArgumentException('Aksi tidak valid.');
    $db->commit();
    $_SESSION['success'] = $pesan;
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    $_SESSION['error'] = $e->getMessage();
}
$rows = $db->query("SELECT k.*,(SELECT COUNT(*) FROM pinjaman p WHERE p.kelompok_angsuran_id=k.id AND p.status IN ('aktif','macet')) pinjaman_aktif,(SELECT COUNT(DISTINCT p.anggota_id) FROM pinjaman p WHERE p.kelompok_angsuran_id=k.id AND p.status IN ('aktif','macet')) anggota_aktif FROM kelompok_angsuran k ORDER BY k.status='aktif' DESC,k.nama")->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Kelompok Angsuran</title><?php $uiAssetPrefix = '../';
                                    include __DIR__ . '/../includes/layout/assets.php'; ?>
</head>

<body><?php include __DIR__ . '/../includes/layout/shell_start.php'; ?><div class="ui-tx-page">
        <header class="ui-dash-header">
            <div class="ui-dash-header-icon"><i class="ti ti-users-group"></i></div>
            <div class="ui-dash-header-copy">
                <p class="ui-dash-eyebrow mb-0">Peminjaman · Admin</p>
                <h1 class="ui-dash-title">Kelompok Angsuran</h1>
                <p class="ui-dash-meta mb-0">Kelompok penagihan terpisah dari kelompok SHU. Nonaktifkan kelompok untuk menghentikan pilihan baru; pinjaman berjalan tetap tercatat.</p>
            </div>
        </header><?php ui_flash(); ?><section class="card card-body mb-4">
            <h2 class="h5">Buat kelompok</h2>
            <form method="post" class="row g-3"><?= layananToken() ?><input type="hidden" name="aksi" value="buat">
                <div class="col-md-3"><label class="form-label">Kode</label><input class="form-control" name="kode" maxlength="24" pattern="[A-Za-z0-9_-]{2,24}" required placeholder="KLP-01"></div>
                <div class="col-md-4"><label class="form-label">Nama kelompok</label><input class="form-control" name="nama" maxlength="120" required placeholder="Kelompok Mojokerto Utara"></div>
                <div class="col-md-5"><label class="form-label">Keterangan</label><input class="form-control" name="keterangan" maxlength="500"></div>
                <div class="col-12"><button class="btn btn-primary">Simpan kelompok</button></div>
            </form>
        </section>
        <section class="card">
            <div class="table-responsive">
                <table class="table table-striped align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Kode</th>
                            <th>Nama</th>
                            <th>Pinjaman aktif</th>
                            <th>Anggota aktif</th>
                            <th>Status</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody><?php foreach ($rows as $r): ?><tr>
                                <td><?= htmlspecialchars($r['kode']) ?></td>
                                <td><strong><?= htmlspecialchars($r['nama']) ?></strong><?php if ($r['keterangan']): ?><small class="d-block text-muted"><?= htmlspecialchars($r['keterangan']) ?></small><?php endif; ?></td>
                                <td><?= (int)$r['pinjaman_aktif'] ?></td>
                                <td><?= (int)$r['anggota_aktif'] ?></td>
                                <td><span class="badge bg-<?= $r['status'] === 'aktif' ? 'success' : 'secondary' ?>"><?= htmlspecialchars(ucfirst($r['status'])) ?></span></td>
                                <td>
                                    <form method="post"><?= layananToken() ?><input type="hidden" name="aksi" value="status"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="status" value="<?= $r['status'] === 'aktif' ? 'nonaktif' : 'aktif' ?>"><button class="btn btn-sm btn-outline-secondary"><?= $r['status'] === 'aktif' ? 'Nonaktifkan' : 'Aktifkan' ?></button></form>
                                </td>
                            </tr><?php endforeach; ?></tbody>
                </table>
            </div><?php if (!$rows): ?><div class="card-body text-muted">Belum ada kelompok penagihan.</div><?php endif; ?>
        </section>
    </div><?php include __DIR__ . '/../includes/layout/shell_end.php'; ?></body>

</html>
