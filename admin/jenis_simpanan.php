<?php
require_once '../includes/auth.php';
require_once '../config/config.php';

$reservedKode = ['ANG', 'BONUS', 'SHU'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $existing = $db->prepare('SELECT kode_jenis FROM jenis_simpanan WHERE id=?');
    $existing->execute([(int)($_POST['id'] ?? 0)]);
    if (in_array($existing->fetchColumn(), $reservedKode, true) || in_array(strtoupper(trim($_POST['kode_jenis'] ?? '')), $reservedKode, true)) {
        $_SESSION['error'] = 'Jenis jurnal ANG, BONUS, dan SHU dikelola sistem dan tidak dapat diubah melalui master simpanan.';
        header('Location: jenis_simpanan.php');
        exit;
    }

    if (isset($_POST['add'])) {
        $kode_jenis = $_POST['kode_jenis'];
        $nama_jenis = $_POST['nama_jenis'];
        $deskripsi = $_POST['deskripsi'];
        $minimum = $_POST['minimum'] ?? 0;
        $maksimum = $_POST['maksimum'] ?? 0;
        $bagi_hasil = $_POST['bagi_hasil'] ?? 0;

        try {
            layananValidasiToken();
            $check_query = "SELECT id FROM jenis_simpanan WHERE kode_jenis = :kode_jenis";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->bindParam(':kode_jenis', $kode_jenis);
            $check_stmt->execute();

            if ($check_stmt->rowCount() > 0) {
                throw new Exception("Kode jenis sudah digunakan");
            }

            $query = "INSERT INTO jenis_simpanan (kode_jenis, nama_jenis, deskripsi, minimum, maksimum, bagi_hasil)
                      VALUES (:kode_jenis, :nama_jenis, :deskripsi, :minimum, :maksimum, :bagi_hasil)";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':kode_jenis', $kode_jenis);
            $stmt->bindParam(':nama_jenis', $nama_jenis);
            $stmt->bindParam(':deskripsi', $deskripsi);
            $stmt->bindParam(':minimum', $minimum);
            $stmt->bindParam(':maksimum', $maksimum);
            $stmt->bindParam(':bagi_hasil', $bagi_hasil);

            if ($stmt->execute()) {
                $_SESSION['success'] = "Jenis simpanan berhasil ditambahkan";
            } else {
                throw new Exception("Gagal menambahkan jenis simpanan");
            }
        } catch (Exception $e) {
            $_SESSION['error'] = $e->getMessage();
        }

        header("Location: jenis_simpanan.php");
        exit();
    }

    if (isset($_POST['edit'])) {
        $id = $_POST['id'];
        $kode_jenis = $_POST['kode_jenis'];
        $nama_jenis = $_POST['nama_jenis'];
        $deskripsi = $_POST['deskripsi'];
        $minimum = $_POST['minimum'] ?? 0;
        $maksimum = $_POST['maksimum'] ?? 0;
        $bagi_hasil = $_POST['bagi_hasil'] ?? 0;
        $status = $_POST['status'];

        try {
            layananValidasiToken();
            $check_query = "SELECT id FROM jenis_simpanan WHERE kode_jenis = :kode_jenis AND id != :id";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->bindParam(':kode_jenis', $kode_jenis);
            $check_stmt->bindParam(':id', $id);
            $check_stmt->execute();

            if ($check_stmt->rowCount() > 0) {
                throw new Exception("Kode jenis sudah digunakan");
            }

            $query = "UPDATE jenis_simpanan SET kode_jenis = :kode_jenis, nama_jenis = :nama_jenis,
                      deskripsi = :deskripsi, minimum = :minimum, maksimum = :maksimum,
                      bagi_hasil = :bagi_hasil, status = :status WHERE id = :id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':id', $id);
            $stmt->bindParam(':kode_jenis', $kode_jenis);
            $stmt->bindParam(':nama_jenis', $nama_jenis);
            $stmt->bindParam(':deskripsi', $deskripsi);
            $stmt->bindParam(':minimum', $minimum);
            $stmt->bindParam(':maksimum', $maksimum);
            $stmt->bindParam(':bagi_hasil', $bagi_hasil);
            $stmt->bindParam(':status', $status);

            if ($stmt->execute()) {
                $_SESSION['success'] = "Jenis simpanan berhasil diupdate";
            } else {
                throw new Exception("Gagal mengupdate jenis simpanan");
            }
        } catch (Exception $e) {
            $_SESSION['error'] = $e->getMessage();
        }

        header("Location: jenis_simpanan.php");
        exit();
    }

    if (isset($_POST['delete'])) {
        $id = $_POST['id'];

        try {
            layananValidasiToken();
            $check_query = "SELECT COUNT(*) as total FROM simpanan WHERE jenis_simpanan_id = :id";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->bindParam(':id', $id);
            $check_stmt->execute();
            $used = $check_stmt->fetch(PDO::FETCH_ASSOC)['total'];

            if ($used > 0) {
                throw new Exception("Tidak dapat menghapus jenis simpanan karena masih digunakan oleh $used data simpanan");
            }

            $check_transaksi_query = "SELECT COUNT(*) as total FROM transaksi WHERE jenis_simpanan_id = :id";
            $check_transaksi_stmt = $db->prepare($check_transaksi_query);
            $check_transaksi_stmt->bindParam(':id', $id);
            $check_transaksi_stmt->execute();
            $used_in_transaksi = $check_transaksi_stmt->fetch(PDO::FETCH_ASSOC)['total'];

            if ($used_in_transaksi > 0) {
                throw new Exception("Tidak dapat menghapus jenis simpanan karena masih digunakan oleh $used_in_transaksi data transaksi");
            }

            $query = "DELETE FROM jenis_simpanan WHERE id = :id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':id', $id);

            if ($stmt->execute()) {
                $_SESSION['success'] = "Jenis simpanan berhasil dihapus";
            } else {
                throw new Exception("Gagal menghapus jenis simpanan");
            }
        } catch (Exception $e) {
            $_SESSION['error'] = $e->getMessage();
        }

        header("Location: jenis_simpanan.php");
        exit();
    }
}

$jenis_simpanan = $db->query(
    "SELECT * FROM jenis_simpanan
     ORDER BY CASE WHEN status = 'aktif' THEN 1 ELSE 2 END, nama_jenis"
)->fetchAll(PDO::FETCH_ASSOC);

$total_jenis = count($jenis_simpanan);
$aktif_count = 0;
$nonaktif_count = 0;
$minimum_count = 0;
$sistem_count = 0;
foreach ($jenis_simpanan as $jenis) {
    if (($jenis['status'] ?? '') === 'aktif') {
        $aktif_count++;
    } else {
        $nonaktif_count++;
    }
    if ((float)$jenis['minimum'] > 0) {
        $minimum_count++;
    }
    if (in_array($jenis['kode_jenis'], $reservedKode, true)) {
        $sistem_count++;
    }
}

$page_title = 'Jenis Simpanan';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Jenis Simpanan - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <?php $uiAssetPrefix = '../'; include __DIR__ . '/../includes/layout/assets.php'; ?>
</head>
<body>
    <?php include __DIR__ . '/../includes/layout/shell_start.php'; ?>
            <div class="ui-tx-page">
                <header class="ui-dash-header">
                    <div class="ui-dash-header-icon ui-tx-icon-jsim" aria-hidden="true"><i class="ti ti-stack-2"></i></div>
                    <div class="ui-dash-header-copy">
                        <p class="ui-dash-eyebrow mb-0">Master data</p>
                        <h1 class="ui-dash-title">Jenis Simpanan</h1>
                        <p class="ui-dash-meta mb-0">Kelola produk tabungan — minimum, maksimum, dan bagi hasil</p>
                    </div>
                    <div class="ui-dash-header-actions">
                        <a href="setoran.php" class="btn ui-tx-btn-jsim-ghost">
                            <i class="ti ti-circle-plus me-1" aria-hidden="true"></i>
                            <span class="d-none d-sm-inline">Setoran</span>
                        </a>
                        <button type="button" class="btn ui-dash-btn-add" data-bs-toggle="modal" data-bs-target="#addModal">
                            <i class="ti ti-plus me-1" aria-hidden="true"></i>
                            <span class="d-none d-sm-inline">Tambah jenis</span><span class="d-sm-none">Tambah</span>
                        </button>
                    </div>
                </header>

                <?php ui_flash(); ?>

                <div class="alert alert-info ui-jsim-info" role="status" data-ui-toast-skip>
                    <i class="ti ti-info-circle me-1" aria-hidden="true"></i>
                    Kode <strong>ANG</strong>, <strong>BONUS</strong>, dan <strong>SHU</strong> dikelola sistem dan tidak dapat diubah dari halaman ini.
                </div>

                <div class="ui-jsim-kpi">
                <?php ui_stat_grid([
                    ['label' => 'Total jenis', 'value' => $total_jenis, 'tone' => 'primary', 'icon' => 'ti ti-stack-2', 'hint' => 'Semua produk'],
                    ['label' => 'Aktif', 'value' => $aktif_count, 'tone' => 'success', 'icon' => 'ti ti-circle-check', 'hint' => 'Dapat dipakai'],
                    ['label' => 'Nonaktif', 'value' => $nonaktif_count, 'tone' => 'warning', 'icon' => 'ti ti-circle-x', 'hint' => 'Ditangguhkan'],
                    ['label' => 'Ada minimum', 'value' => $minimum_count, 'tone' => 'info', 'icon' => 'ti ti-coins', 'hint' => $sistem_count ? $sistem_count . ' jurnal sistem' : 'Setoran awal'],
                ]); ?>
                </div>

                <div class="card ui-jsim-daftar">
                    <div class="card-header ui-dash-sec">
                        <span class="ui-dash-sec-title"><i class="ti ti-list" aria-hidden="true"></i>Daftar jenis simpanan</span>
                        <span class="ui-dash-sec-note"><?php echo (int)$total_jenis; ?> data</span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive ui-table-scroll">
                            <table class="table table-hover table-sm align-middle mb-0 js-datatable"
                                   data-dt-page-length="10"
                                   data-dt-order="2,asc"
                                   data-dt-filter="7"
                                   data-empty-message="Belum ada jenis simpanan.">
                                <thead>
                                    <tr>
                                        <th class="text-muted">#</th>
                                        <th>Kode</th>
                                        <th>Nama jenis</th>
                                        <th>Deskripsi</th>
                                        <th class="text-end">Minimum</th>
                                        <th class="text-end">Maksimum</th>
                                        <th class="text-end">Bagi hasil</th>
                                        <th>Status</th>
                                        <th class="text-end no-sort">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($jenis_simpanan as $index => $jenis):
                                    $isSistem = in_array($jenis['kode_jenis'], $reservedKode, true);
                                    $isAktif = ($jenis['status'] ?? '') === 'aktif';
                                ?>
                                    <tr>
                                        <td class="text-muted"><?php echo (int)($index + 1); ?></td>
                                        <td><span class="ui-jsim-kode"><?php echo htmlspecialchars($jenis['kode_jenis']); ?></span></td>
                                        <td>
                                            <strong class="ui-jsim-name"><?php echo htmlspecialchars($jenis['nama_jenis']); ?></strong>
                                            <?php if ($isSistem): ?>
                                            <small class="ui-jsim-sys">Jurnal sistem</small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-muted"><?php echo trim((string)($jenis['deskripsi'] ?? '')) !== '' ? ui_cell_ellipsis((string)$jenis['deskripsi'], 40) : '—'; ?></td>
                                        <td class="text-end ui-jsim-money"><?php echo formatRupiah($jenis['minimum']); ?></td>
                                        <td class="text-end ui-jsim-money"><?php echo (float)$jenis['maksimum'] > 0 ? formatRupiah($jenis['maksimum']) : '—'; ?></td>
                                        <td class="text-end">
                                            <?php if ((float)$jenis['bagi_hasil'] > 0): ?>
                                            <span class="badge bg-success-subtle text-success"><?php echo htmlspecialchars(rtrim(rtrim(number_format((float)$jenis['bagi_hasil'], 2, ',', '.'), '0'), ',')); ?>%</span>
                                            <?php else: ?>
                                            <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-filter="<?php echo $isAktif ? 'Aktif' : 'Nonaktif'; ?>">
                                            <span class="badge <?php echo $isAktif ? 'bg-success' : 'bg-secondary'; ?>">
                                                <?php echo $isAktif ? 'Aktif' : 'Nonaktif'; ?>
                                            </span>
                                        </td>
                                        <td class="table-actions text-end">
                                            <?php if ($isSistem): ?>
                                            <span class="ui-jsim-locked"><i class="ti ti-lock" aria-hidden="true"></i>Sistem</span>
                                            <?php else:
                                                echo ui_action_menu([
                                                    [
                                                        'label' => 'Edit',
                                                        'icon' => 'ti ti-edit',
                                                        'class' => 'text-warning',
                                                        'attrs' => [
                                                            'data-bs-toggle' => 'modal',
                                                            'data-bs-target' => '#editModal',
                                                            'data-id' => (int)$jenis['id'],
                                                            'data-kode' => $jenis['kode_jenis'],
                                                            'data-nama' => $jenis['nama_jenis'],
                                                            'data-deskripsi' => (string)($jenis['deskripsi'] ?? ''),
                                                            'data-minimum' => (string)$jenis['minimum'],
                                                            'data-maksimum' => (string)$jenis['maksimum'],
                                                            'data-bagihasil' => (string)$jenis['bagi_hasil'],
                                                            'data-status' => $jenis['status'],
                                                        ],
                                                    ],
                                                    [
                                                        'label' => 'Hapus',
                                                        'icon' => 'ti ti-trash',
                                                        'class' => 'text-danger',
                                                        'attrs' => [
                                                            'data-bs-toggle' => 'modal',
                                                            'data-bs-target' => '#deleteModal',
                                                            'data-id' => (int)$jenis['id'],
                                                            'data-nama' => $jenis['nama_jenis'],
                                                        ],
                                                    ],
                                                ]);
                                            endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        <?php include __DIR__ . '/../includes/layout/shell_end.php'; ?>

    <div class="modal fade ui-jsim-modal" id="addModal" tabindex="-1" aria-labelledby="addModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
            <div class="modal-content">
                <form method="POST" class="ui-jsim-form-root" data-jsim-form="add" novalidate><?php echo layananToken(); ?>
                    <div class="modal-header">
                        <div>
                            <p class="ui-jsim-modal-eyebrow mb-0">Master simpanan</p>
                            <h2 class="modal-title h5" id="addModalLabel">Tambah jenis simpanan</h2>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                    </div>
                    <div class="modal-body">
                        <div class="ui-jsim-form">
                            <section class="ui-jsim-section">
                                <header class="ui-jsim-section-head">
                                    <i class="ti ti-id" aria-hidden="true"></i>
                                    <div>
                                        <strong>Identitas produk</strong>
                                        <span>Kode unik dan nama yang tampil di setoran</span>
                                    </div>
                                </header>
                                <div class="ui-jsim-field-grid">
                                    <div class="ui-jsim-field">
                                        <label class="form-label" for="add_kode">Kode jenis *</label>
                                        <div class="ui-jsim-kode-wrap">
                                            <span class="ui-jsim-kode-prefix">KODE</span>
                                            <input type="text" name="kode_jenis" id="add_kode" class="form-control ui-jsim-kode-input"
                                                   required placeholder="POKOK" maxlength="10" autocomplete="off"
                                                   data-jsim="kode">
                                        </div>
                                        <p class="form-text mb-0">Huruf besar otomatis · jangan ANG / BONUS / SHU</p>
                                    </div>
                                    <div class="ui-jsim-field">
                                        <label class="form-label" for="add_nama">Nama jenis *</label>
                                        <input type="text" name="nama_jenis" id="add_nama" class="form-control" required
                                               placeholder="Simpanan Pokok" data-jsim="nama">
                                    </div>
                                </div>
                                <div class="ui-jsim-field">
                                    <label class="form-label" for="add_deskripsi">Deskripsi</label>
                                    <textarea name="deskripsi" id="add_deskripsi" class="form-control" rows="2"
                                              placeholder="Opsional — penjelasan singkat untuk petugas" data-jsim="deskripsi"></textarea>
                                </div>
                            </section>

                            <section class="ui-jsim-section">
                                <header class="ui-jsim-section-head">
                                    <i class="ti ti-cash" aria-hidden="true"></i>
                                    <div>
                                        <strong>Batas setoran</strong>
                                        <span>0 = tanpa batas · isi dalam Rupiah</span>
                                    </div>
                                </header>
                                <div class="ui-jsim-field-grid">
                                    <div class="ui-jsim-field">
                                        <label class="form-label" for="add_minimum_disp">Minimum</label>
                                        <div class="input-group ui-jsim-money-group">
                                            <span class="input-group-text">Rp</span>
                                            <input type="text" id="add_minimum_disp" class="form-control form-control-lg"
                                                   inputmode="numeric" autocomplete="off" placeholder="0" data-jsim-money="minimum">
                                        </div>
                                        <input type="hidden" name="minimum" id="add_minimum" value="0">
                                        <div class="ui-tx-chips ui-jsim-chips" data-jsim-chips="minimum">
                                            <button type="button" class="ui-tx-chip" data-amount="0">Tanpa min</button>
                                            <button type="button" class="ui-tx-chip" data-amount="50000">50 rb</button>
                                            <button type="button" class="ui-tx-chip" data-amount="100000">100 rb</button>
                                            <button type="button" class="ui-tx-chip" data-amount="250000">250 rb</button>
                                        </div>
                                    </div>
                                    <div class="ui-jsim-field">
                                        <label class="form-label" for="add_maksimum_disp">Maksimum</label>
                                        <div class="input-group ui-jsim-money-group">
                                            <span class="input-group-text">Rp</span>
                                            <input type="text" id="add_maksimum_disp" class="form-control form-control-lg"
                                                   inputmode="numeric" autocomplete="off" placeholder="0" data-jsim-money="maksimum">
                                        </div>
                                        <input type="hidden" name="maksimum" id="add_maksimum" value="0">
                                        <div class="ui-tx-chips ui-jsim-chips" data-jsim-chips="maksimum">
                                            <button type="button" class="ui-tx-chip" data-amount="0">Tanpa maks</button>
                                            <button type="button" class="ui-tx-chip" data-amount="1000000">1 jt</button>
                                            <button type="button" class="ui-tx-chip" data-amount="5000000">5 jt</button>
                                            <button type="button" class="ui-tx-chip" data-amount="10000000">10 jt</button>
                                        </div>
                                    </div>
                                </div>
                            </section>

                            <section class="ui-jsim-section">
                                <header class="ui-jsim-section-head">
                                    <i class="ti ti-percentage" aria-hidden="true"></i>
                                    <div>
                                        <strong>Bagi hasil</strong>
                                        <span>Persentase per tahun (opsional)</span>
                                    </div>
                                </header>
                                <div class="ui-jsim-field ui-jsim-field-bagi">
                                    <label class="form-label" for="add_bagi">Bagi hasil</label>
                                    <div class="input-group ui-jsim-pct-group">
                                        <input type="number" name="bagi_hasil" id="add_bagi" class="form-control form-control-lg"
                                               value="0" step="0.01" min="0" max="100" data-jsim="bagi">
                                        <span class="input-group-text">% / tahun</span>
                                    </div>
                                </div>
                            </section>

                            <aside class="ui-jsim-preview" data-jsim-preview aria-live="polite">
                                <span class="ui-jsim-preview-label">Pratinjau</span>
                                <div class="ui-jsim-preview-body">
                                    <code data-preview="kode">—</code>
                                    <strong data-preview="nama">Jenis baru</strong>
                                    <p data-preview="deskripsi" class="mb-0">Belum ada deskripsi</p>
                                </div>
                                <dl class="ui-jsim-preview-meta">
                                    <div><dt>Min</dt><dd data-preview="minimum">Rp 0</dd></div>
                                    <div><dt>Maks</dt><dd data-preview="maksimum">Tanpa batas</dd></div>
                                    <div><dt>Bagi hasil</dt><dd data-preview="bagi">0%</dd></div>
                                    <div><dt>Status</dt><dd data-preview="status">Aktif</dd></div>
                                </dl>
                            </aside>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" name="add" value="1" class="btn btn-primary">
                            <i class="ti ti-device-floppy me-1" aria-hidden="true"></i>Simpan
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade ui-jsim-modal is-edit" id="editModal" tabindex="-1" aria-labelledby="editModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
            <div class="modal-content">
                <form method="POST" class="ui-jsim-form-root" data-jsim-form="edit" novalidate><?php echo layananToken(); ?>
                    <div class="modal-header">
                        <div>
                            <p class="ui-jsim-modal-eyebrow mb-0">Ubah produk</p>
                            <h2 class="modal-title h5" id="editModalLabel">Edit jenis simpanan</h2>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="id" id="edit_id">
                        <div class="ui-jsim-form">
                            <section class="ui-jsim-section">
                                <header class="ui-jsim-section-head">
                                    <i class="ti ti-id" aria-hidden="true"></i>
                                    <div>
                                        <strong>Identitas produk</strong>
                                        <span>Perbarui kode atau nama bila perlu</span>
                                    </div>
                                </header>
                                <div class="ui-jsim-field-grid">
                                    <div class="ui-jsim-field">
                                        <label class="form-label" for="edit_kode">Kode jenis *</label>
                                        <div class="ui-jsim-kode-wrap">
                                            <span class="ui-jsim-kode-prefix">KODE</span>
                                            <input type="text" name="kode_jenis" id="edit_kode" class="form-control ui-jsim-kode-input"
                                                   required maxlength="10" autocomplete="off" data-jsim="kode">
                                        </div>
                                    </div>
                                    <div class="ui-jsim-field">
                                        <label class="form-label" for="edit_nama">Nama jenis *</label>
                                        <input type="text" name="nama_jenis" id="edit_nama" class="form-control" required data-jsim="nama">
                                    </div>
                                </div>
                                <div class="ui-jsim-field">
                                    <label class="form-label" for="edit_deskripsi">Deskripsi</label>
                                    <textarea name="deskripsi" id="edit_deskripsi" class="form-control" rows="2" data-jsim="deskripsi"></textarea>
                                </div>
                            </section>

                            <section class="ui-jsim-section">
                                <header class="ui-jsim-section-head">
                                    <i class="ti ti-cash" aria-hidden="true"></i>
                                    <div>
                                        <strong>Batas setoran</strong>
                                        <span>0 = tanpa batas</span>
                                    </div>
                                </header>
                                <div class="ui-jsim-field-grid">
                                    <div class="ui-jsim-field">
                                        <label class="form-label" for="edit_minimum_disp">Minimum</label>
                                        <div class="input-group ui-jsim-money-group">
                                            <span class="input-group-text">Rp</span>
                                            <input type="text" id="edit_minimum_disp" class="form-control form-control-lg"
                                                   inputmode="numeric" autocomplete="off" placeholder="0" data-jsim-money="minimum">
                                        </div>
                                        <input type="hidden" name="minimum" id="edit_minimum" value="0">
                                        <div class="ui-tx-chips ui-jsim-chips" data-jsim-chips="minimum">
                                            <button type="button" class="ui-tx-chip" data-amount="0">Tanpa min</button>
                                            <button type="button" class="ui-tx-chip" data-amount="50000">50 rb</button>
                                            <button type="button" class="ui-tx-chip" data-amount="100000">100 rb</button>
                                            <button type="button" class="ui-tx-chip" data-amount="250000">250 rb</button>
                                        </div>
                                    </div>
                                    <div class="ui-jsim-field">
                                        <label class="form-label" for="edit_maksimum_disp">Maksimum</label>
                                        <div class="input-group ui-jsim-money-group">
                                            <span class="input-group-text">Rp</span>
                                            <input type="text" id="edit_maksimum_disp" class="form-control form-control-lg"
                                                   inputmode="numeric" autocomplete="off" placeholder="0" data-jsim-money="maksimum">
                                        </div>
                                        <input type="hidden" name="maksimum" id="edit_maksimum" value="0">
                                        <div class="ui-tx-chips ui-jsim-chips" data-jsim-chips="maksimum">
                                            <button type="button" class="ui-tx-chip" data-amount="0">Tanpa maks</button>
                                            <button type="button" class="ui-tx-chip" data-amount="1000000">1 jt</button>
                                            <button type="button" class="ui-tx-chip" data-amount="5000000">5 jt</button>
                                            <button type="button" class="ui-tx-chip" data-amount="10000000">10 jt</button>
                                        </div>
                                    </div>
                                </div>
                            </section>

                            <section class="ui-jsim-section">
                                <header class="ui-jsim-section-head">
                                    <i class="ti ti-toggle-left" aria-hidden="true"></i>
                                    <div>
                                        <strong>Bagi hasil &amp; status</strong>
                                        <span>Nonaktifkan bila produk tidak lagi ditawarkan</span>
                                    </div>
                                </header>
                                <div class="ui-jsim-field-grid">
                                    <div class="ui-jsim-field">
                                        <label class="form-label" for="edit_bagihasil">Bagi hasil</label>
                                        <div class="input-group ui-jsim-pct-group">
                                            <input type="number" name="bagi_hasil" id="edit_bagihasil" class="form-control form-control-lg"
                                                   step="0.01" min="0" max="100" data-jsim="bagi">
                                            <span class="input-group-text">% / tahun</span>
                                        </div>
                                    </div>
                                    <div class="ui-jsim-field">
                                        <span class="form-label d-block">Status</span>
                                        <div class="ui-jsim-status" role="radiogroup" aria-label="Status jenis">
                                            <label class="ui-jsim-status-opt">
                                                <input type="radio" name="status" id="edit_status_aktif" value="aktif" data-jsim="status" checked>
                                                <span><i class="ti ti-circle-check" aria-hidden="true"></i>Aktif</span>
                                            </label>
                                            <label class="ui-jsim-status-opt">
                                                <input type="radio" name="status" id="edit_status_nonaktif" value="nonaktif" data-jsim="status">
                                                <span><i class="ti ti-circle-x" aria-hidden="true"></i>Nonaktif</span>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </section>

                            <aside class="ui-jsim-preview" data-jsim-preview aria-live="polite">
                                <span class="ui-jsim-preview-label">Pratinjau</span>
                                <div class="ui-jsim-preview-body">
                                    <code data-preview="kode">—</code>
                                    <strong data-preview="nama">—</strong>
                                    <p data-preview="deskripsi" class="mb-0">—</p>
                                </div>
                                <dl class="ui-jsim-preview-meta">
                                    <div><dt>Min</dt><dd data-preview="minimum">Rp 0</dd></div>
                                    <div><dt>Maks</dt><dd data-preview="maksimum">Tanpa batas</dd></div>
                                    <div><dt>Bagi hasil</dt><dd data-preview="bagi">0%</dd></div>
                                    <div><dt>Status</dt><dd data-preview="status">Aktif</dd></div>
                                </dl>
                            </aside>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" name="edit" value="1" class="btn btn-warning">
                            <i class="ti ti-check me-1" aria-hidden="true"></i>Update
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade ui-jsim-modal is-danger" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST" id="deleteForm"><?php echo layananToken(); ?>
                    <div class="modal-header">
                        <h2 class="modal-title h5" id="deleteModalLabel">Hapus jenis simpanan</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="id" id="delete_id">
                        <input type="hidden" name="delete" value="1">
                        <div class="ui-jsim-delete">
                            <i class="ti ti-alert-triangle" aria-hidden="true"></i>
                            <div>
                                <p class="mb-1">Hapus jenis simpanan berikut?</p>
                                <strong id="delete_nama"></strong>
                                <p class="text-muted small mb-0 mt-2">Hanya bisa dihapus jika belum dipakai di simpanan atau transaksi.</p>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-danger">
                            <i class="ti ti-trash me-1" aria-hidden="true"></i>Hapus
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
    (function () {
        ['addModal', 'editModal', 'deleteModal'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el && el.parentElement !== document.body) document.body.appendChild(el);
        });

        function digits(v) {
            return String(v == null ? '' : v).replace(/\D+/g, '');
        }
        function fmtRp(n) {
            n = Math.round(Number(n) || 0);
            return 'Rp ' + n.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        }
        function fmtNum(n) {
            n = Math.round(Number(n) || 0);
            return n === 0 ? '' : n.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        }
        function setMoney(form, key, value) {
            var raw = Math.max(0, Math.round(Number(value) || 0));
            var hidden = form.querySelector('[name="' + key + '"]');
            var disp = form.querySelector('[data-jsim-money="' + key + '"]');
            if (hidden) hidden.value = String(raw);
            if (disp) disp.value = fmtNum(raw);
            syncPreview(form);
        }
        function syncPreview(form) {
            var box = form.querySelector('[data-jsim-preview]');
            if (!box) return;
            var kode = (form.querySelector('[data-jsim="kode"]') || {}).value || '';
            var nama = (form.querySelector('[data-jsim="nama"]') || {}).value || '';
            var desk = (form.querySelector('[data-jsim="deskripsi"]') || {}).value || '';
            var bagi = parseFloat((form.querySelector('[data-jsim="bagi"]') || {}).value || '0') || 0;
            var min = parseFloat((form.querySelector('[name="minimum"]') || {}).value || '0') || 0;
            var max = parseFloat((form.querySelector('[name="maksimum"]') || {}).value || '0') || 0;
            var statusEl = form.querySelector('[data-jsim="status"]:checked') || form.querySelector('[name="status"]');
            var status = statusEl ? statusEl.value : 'aktif';
            var map = {
                kode: kode ? kode.toUpperCase() : '—',
                nama: nama || 'Jenis baru',
                deskripsi: desk.trim() || 'Belum ada deskripsi',
                minimum: min > 0 ? fmtRp(min) : 'Tanpa minimum',
                maksimum: max > 0 ? fmtRp(max) : 'Tanpa batas',
                bagi: (bagi > 0 ? String(bagi).replace('.', ',') : '0') + '%',
                status: status === 'aktif' ? 'Aktif' : 'Nonaktif'
            };
            Object.keys(map).forEach(function (k) {
                var el = box.querySelector('[data-preview="' + k + '"]');
                if (el) el.textContent = map[k];
            });
            box.classList.toggle('is-inactive', status !== 'aktif');
        }
        function bindForm(form) {
            if (!form || form.dataset.jsimReady) return;
            form.dataset.jsimReady = '1';

            var kode = form.querySelector('[data-jsim="kode"]');
            if (kode) {
                kode.addEventListener('input', function () {
                    var start = kode.selectionStart;
                    kode.value = kode.value.toUpperCase().replace(/[^A-Z0-9_-]/g, '');
                    try { kode.setSelectionRange(start, start); } catch (e) {}
                    syncPreview(form);
                });
            }

            form.querySelectorAll('[data-jsim-money]').forEach(function (disp) {
                var key = disp.getAttribute('data-jsim-money');
                disp.addEventListener('input', function () {
                    setMoney(form, key, digits(disp.value));
                    var pos = disp.value.length;
                    try { disp.setSelectionRange(pos, pos); } catch (e) {}
                });
                disp.addEventListener('blur', function () {
                    setMoney(form, key, digits(disp.value));
                });
            });

            form.querySelectorAll('[data-jsim-chips]').forEach(function (wrap) {
                var key = wrap.getAttribute('data-jsim-chips');
                wrap.querySelectorAll('[data-amount]').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        setMoney(form, key, btn.getAttribute('data-amount'));
                        wrap.querySelectorAll('.ui-tx-chip').forEach(function (c) { c.classList.remove('is-active'); });
                        btn.classList.add('is-active');
                    });
                });
            });

            ['nama', 'deskripsi', 'bagi'].forEach(function (name) {
                var el = form.querySelector('[data-jsim="' + name + '"]');
                if (el) el.addEventListener('input', function () { syncPreview(form); });
            });
            form.querySelectorAll('[data-jsim="status"]').forEach(function (el) {
                el.addEventListener('change', function () { syncPreview(form); });
            });

            syncPreview(form);
        }

        document.querySelectorAll('.ui-jsim-form-root').forEach(bindForm);

        var addModal = document.getElementById('addModal');
        if (addModal) {
            addModal.addEventListener('show.bs.modal', function () {
                var form = addModal.querySelector('.ui-jsim-form-root');
                if (!form) return;
                form.reset();
                setMoney(form, 'minimum', 0);
                setMoney(form, 'maksimum', 0);
                var bagi = form.querySelector('[data-jsim="bagi"]');
                if (bagi) bagi.value = '0';
                form.querySelectorAll('.ui-tx-chip.is-active').forEach(function (c) { c.classList.remove('is-active'); });
                syncPreview(form);
            });
        }

        var editModal = document.getElementById('editModal');
        if (editModal) {
            editModal.addEventListener('show.bs.modal', function (event) {
                var button = event.relatedTarget;
                var form = editModal.querySelector('.ui-jsim-form-root');
                if (!button || !form) return;
                document.getElementById('edit_id').value = button.getAttribute('data-id') || '';
                document.getElementById('edit_kode').value = (button.getAttribute('data-kode') || '').toUpperCase();
                document.getElementById('edit_nama').value = button.getAttribute('data-nama') || '';
                document.getElementById('edit_deskripsi').value = button.getAttribute('data-deskripsi') || '';
                document.getElementById('edit_bagihasil').value = button.getAttribute('data-bagihasil') || '0';
                setMoney(form, 'minimum', button.getAttribute('data-minimum') || 0);
                setMoney(form, 'maksimum', button.getAttribute('data-maksimum') || 0);
                var st = button.getAttribute('data-status') || 'aktif';
                var aktif = document.getElementById('edit_status_aktif');
                var non = document.getElementById('edit_status_nonaktif');
                if (aktif) aktif.checked = st === 'aktif';
                if (non) non.checked = st !== 'aktif';
                form.querySelectorAll('.ui-tx-chip.is-active').forEach(function (c) { c.classList.remove('is-active'); });
                syncPreview(form);
            });
        }

        var deleteModal = document.getElementById('deleteModal');
        if (deleteModal) {
            deleteModal.addEventListener('show.bs.modal', function (event) {
                var button = event.relatedTarget;
                if (!button) return;
                document.getElementById('delete_id').value = button.getAttribute('data-id') || '';
                document.getElementById('delete_nama').textContent = button.getAttribute('data-nama') || '';
            });
        }
    })();
    </script>
</body>
</html>
