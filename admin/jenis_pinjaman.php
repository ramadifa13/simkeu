<?php
require_once '../includes/auth.php';
require_once '../config/config.php';

if (!isLoggedIn() || !isAdmin()) {
    $_SESSION['error'] = "Anda tidak memiliki akses ke halaman ini";
    header("Location: ../login.php");
    exit();
}

/**
 * Ambil & validasi field jenis pinjaman dari POST (tarif per tenor + frekuensi).
 * Validasi yang sama juga dilakukan di JS (form).
 */
function ambilInputJenisPinjaman() {
    $is_gadai = !empty($_POST['memerlukan_jaminan']);
    $data = [
        'kode_jenis' => trim($_POST['kode_jenis']),
        'nama_jenis' => trim($_POST['nama_jenis']),
        'maksimal_pinjaman' => parseRupiah($_POST['maksimal_pinjaman']),
        'deskripsi' => isset($_POST['deskripsi']) ? $_POST['deskripsi'] : '',
    ];
    if ($data['maksimal_pinjaman'] === false) {
        throw new Exception("Maksimal pinjaman harus berupa angka rupiah penuh (tanpa desimal)");
    }

    $frekuensi = $is_gadai
        ? ['bulanan', 'mingguan', 'dua_mingguan']
        : (isset($_POST['frekuensi']) ? (array) $_POST['frekuensi'] : []);
    $frekuensi = array_values(array_intersect(['bulanan', 'mingguan', 'dua_mingguan'], $frekuensi));
    if (empty($frekuensi)) throw new Exception("Pilih minimal satu frekuensi angsuran");
    $data['frekuensi_diizinkan'] = implode(',', $frekuensi);
    $data['memerlukan_jaminan'] = $is_gadai ? 1 : 0;

    $tenors = isset($_POST['tarif_tenor']) ? (array) $_POST['tarif_tenor'] : [];
    $bungas = isset($_POST['tarif_bunga']) ? (array) $_POST['tarif_bunga'] : [];
    $tarif = $is_gadai ? [1 => 15, 2 => 15, 3 => 15, 4 => 15, 5 => 15, 6 => 15] : [];
    foreach ($is_gadai ? [] : $tenors as $i => $tenor) {
        $tenor = trim($tenor);
        $bunga = isset($bungas[$i]) ? trim($bungas[$i]) : '';
        if ($tenor === '' && $bunga === '') {
            continue;
        }
        if (!ctype_digit($tenor) || (int) $tenor < 1 || (int) $tenor > 36) {
            throw new Exception("Tenor harus bilangan bulat 1–36 bulan");
        }
        if (!is_numeric($bunga) || (float) $bunga < 0 || (float) $bunga > BUNGA_MAKS) {
            throw new Exception("Bunga tenor $tenor bulan harus antara 0 dan " . BUNGA_MAKS . "%");
        }
        if (isset($tarif[(int) $tenor])) {
            throw new Exception("Tenor $tenor bulan diisi lebih dari sekali");
        }
        $tarif[(int) $tenor] = round((float) $bunga, 2);
    }
    if (empty($tarif)) {
        throw new Exception("Isi minimal satu tarif bunga per tenor");
    }
    ksort($tarif);
    $data['tarif'] = $tarif;
    $data['tenor_max'] = max(array_keys($tarif));
    if ($data['memerlukan_jaminan']) {
        if ($data['tenor_max'] > 6) {
            throw new Exception("Tenor pinjaman gadai maksimal 6 bulan");
        }
        foreach ($tarif as $tenor_gadai => $bunga_gadai) {
            if ((float) $bunga_gadai != 15.0) {
                throw new Exception("Bunga dasar gadai untuk tenor $tenor_gadai bulan harus 15%");
            }
        }
    } elseif (in_array('dua_mingguan', $frekuensi, true)) {
        if (!isset($tarif[6]) || (float) $tarif[6] != 45.0) {
            throw new Exception("Pinjaman tanpa jaminan 2 minggu sekali wajib memiliki tenor 6 bulan dengan bunga 45%");
        }
    }
    return $data;
}

function simpanTarifJenisPinjaman($db, $jenis_id, $tarif) {
    $db->prepare("DELETE FROM jenis_pinjaman_tarif WHERE jenis_pinjaman_id = ?")->execute([$jenis_id]);
    $stmt = $db->prepare("INSERT INTO jenis_pinjaman_tarif (jenis_pinjaman_id, tenor_bulan, bunga) VALUES (?, ?, ?)");
    foreach ($tarif as $tenor => $bunga) {
        $stmt->execute([$jenis_id, $tenor, $bunga]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add'])) {
        try {
            layananValidasiToken();
            $data = ambilInputJenisPinjaman();

            $check_stmt = $db->prepare("SELECT id FROM jenis_pinjaman WHERE kode_jenis = ?");
            $check_stmt->execute([$data['kode_jenis']]);
            if ($check_stmt->rowCount() > 0) {
                throw new Exception("Kode jenis sudah digunakan");
            }

            $db->beginTransaction();
            $stmt = $db->prepare("INSERT INTO jenis_pinjaman
                (kode_jenis, nama_jenis, maksimal_pinjaman, tenor_max, bunga, frekuensi_diizinkan, memerlukan_jaminan, deskripsi)
                VALUES (?, ?, ?, ?, 0, ?, ?, ?)");
            $stmt->execute([$data['kode_jenis'], $data['nama_jenis'], $data['maksimal_pinjaman'],
                            $data['tenor_max'], $data['frekuensi_diizinkan'], $data['memerlukan_jaminan'], $data['deskripsi']]);
            simpanTarifJenisPinjaman($db, $db->lastInsertId(), $data['tarif']);
            $db->commit();
            $_SESSION['success'] = "Jenis pinjaman berhasil ditambahkan";
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $_SESSION['error'] = $e->getMessage();
        }

        header("Location: jenis_pinjaman.php");
        exit();
    }

    if (isset($_POST['edit'])) {
        $id = $_POST['id'];
        $status = $_POST['status'] === 'nonaktif' ? 'nonaktif' : 'aktif';

        try {
            layananValidasiToken();
            $data = ambilInputJenisPinjaman();

            $check_stmt = $db->prepare("SELECT id FROM jenis_pinjaman WHERE kode_jenis = ? AND id != ?");
            $check_stmt->execute([$data['kode_jenis'], $id]);
            if ($check_stmt->rowCount() > 0) {
                throw new Exception("Kode jenis sudah digunakan");
            }

            $db->beginTransaction();
            $stmt = $db->prepare("UPDATE jenis_pinjaman SET kode_jenis = ?, nama_jenis = ?,
                      maksimal_pinjaman = ?, tenor_max = ?, frekuensi_diizinkan = ?, memerlukan_jaminan = ?,
                      deskripsi = ?, status = ? WHERE id = ?");
            $stmt->execute([$data['kode_jenis'], $data['nama_jenis'], $data['maksimal_pinjaman'],
                            $data['tenor_max'], $data['frekuensi_diizinkan'], $data['memerlukan_jaminan'], $data['deskripsi'], $status, $id]);
            simpanTarifJenisPinjaman($db, $id, $data['tarif']);
            $db->commit();
            $_SESSION['success'] = "Jenis pinjaman berhasil diupdate";
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $_SESSION['error'] = $e->getMessage();
        }

        header("Location: jenis_pinjaman.php");
        exit();
    }

    if (isset($_POST['delete'])) {
        $id = $_POST['id'];

        try {
            layananValidasiToken();
            $check_pengajuan_query = "SELECT COUNT(*) as total FROM pengajuan_pinjaman WHERE jenis_pinjaman_id = :id";
            $check_pengajuan_stmt = $db->prepare($check_pengajuan_query);
            $check_pengajuan_stmt->bindParam(':id', $id);
            $check_pengajuan_stmt->execute();
            $used_in_pengajuan = $check_pengajuan_stmt->fetch(PDO::FETCH_ASSOC)['total'];

            if ($used_in_pengajuan > 0) {
                throw new Exception("Tidak dapat menghapus jenis pinjaman karena masih digunakan oleh $used_in_pengajuan data pengajuan");
            }

            $check_pinjaman_query = "SELECT COUNT(*) as total FROM pinjaman WHERE jenis_pinjaman_id = :id";
            $check_pinjaman_stmt = $db->prepare($check_pinjaman_query);
            $check_pinjaman_stmt->bindParam(':id', $id);
            $check_pinjaman_stmt->execute();
            $used_in_pinjaman = $check_pinjaman_stmt->fetch(PDO::FETCH_ASSOC)['total'];

            if ($used_in_pinjaman > 0) {
                throw new Exception("Tidak dapat menghapus jenis pinjaman karena masih digunakan oleh $used_in_pinjaman data pinjaman");
            }

            $query = "DELETE FROM jenis_pinjaman WHERE id = :id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':id', $id);

            if ($stmt->execute()) {
                $_SESSION['success'] = "Jenis pinjaman berhasil dihapus";
            } else {
                throw new Exception("Gagal menghapus jenis pinjaman");
            }
        } catch (Exception $e) {
            $_SESSION['error'] = $e->getMessage();
        }

        header("Location: jenis_pinjaman.php");
        exit();
    }
}

$jenis_pinjaman = $db->query(
    "SELECT * FROM jenis_pinjaman
     ORDER BY CASE WHEN status = 'aktif' THEN 1 ELSE 2 END, created_at DESC"
)->fetchAll(PDO::FETCH_ASSOC);

foreach ($jenis_pinjaman as &$jp) {
    $jp['tarif'] = getTarifJenisPinjaman($db, $jp['id']);
}
unset($jp);

$total_jenis = count($jenis_pinjaman);
$aktif = 0;
$nonaktif = 0;
$gadai = 0;
foreach ($jenis_pinjaman as $j) {
    if (($j['status'] ?? '') === 'aktif') {
        $aktif++;
    } else {
        $nonaktif++;
    }
    if (!empty($j['memerlukan_jaminan'])) {
        $gadai++;
    }
}

$page_title = 'Jenis Pinjaman';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Jenis Pinjaman - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <?php $uiAssetPrefix = '../'; include __DIR__ . '/../includes/layout/assets.php'; ?>
</head>
<body>
    <?php include __DIR__ . '/../includes/layout/shell_start.php'; ?>
            <div class="ui-tx-page">
                <header class="ui-dash-header">
                    <div class="ui-dash-header-icon ui-tx-icon-jpin" aria-hidden="true"><i class="ti ti-moneybag"></i></div>
                    <div class="ui-dash-header-copy">
                        <p class="ui-dash-eyebrow mb-0">Master data</p>
                        <h1 class="ui-dash-title">Jenis Pinjaman</h1>
                        <p class="ui-dash-meta mb-0">Produk pinjaman, tarif flat per tenor, dan frekuensi angsuran</p>
                    </div>
                    <div class="ui-dash-header-actions">
                        <a href="pengajuan.php" class="btn ui-tx-btn-jpin-ghost">
                            <i class="ti ti-file-plus me-1" aria-hidden="true"></i>
                            <span class="d-none d-sm-inline">Pengajuan</span>
                        </a>
                        <button type="button" class="btn ui-dash-btn-add" data-bs-toggle="modal" data-bs-target="#addModal">
                            <i class="ti ti-plus me-1" aria-hidden="true"></i>
                            <span class="d-none d-sm-inline">Tambah jenis</span><span class="d-sm-none">Tambah</span>
                        </button>
                    </div>
                </header>

                <?php ui_flash(); ?>

                <div class="alert alert-info ui-jpin-info" role="status" data-ui-toast-skip>
                    <i class="ti ti-info-circle me-1" aria-hidden="true"></i>
                    Tarif flat wajib diisi agar produk muncul di form pengajuan. Gadai memakai bunga dasar otomatis.
                </div>

                <div class="ui-jpin-kpi">
                <?php ui_stat_grid([
                    ['label' => 'Total jenis', 'value' => $total_jenis, 'tone' => 'primary', 'icon' => 'ti ti-moneybag', 'hint' => 'Produk pinjaman'],
                    ['label' => 'Aktif', 'value' => $aktif, 'tone' => 'success', 'icon' => 'ti ti-circle-check', 'hint' => 'Dapat diajukan'],
                    ['label' => 'Nonaktif', 'value' => $nonaktif, 'tone' => 'warning', 'icon' => 'ti ti-circle-x', 'hint' => 'Ditangguhkan'],
                    ['label' => 'Gadai', 'value' => $gadai, 'tone' => 'info', 'icon' => 'ti ti-building-warehouse', 'hint' => 'Wajib jaminan'],
                ]); ?>
                </div>

                <div class="card ui-jpin-daftar">
                    <div class="card-header ui-dash-sec">
                        <span class="ui-dash-sec-title"><i class="ti ti-list" aria-hidden="true"></i>Daftar jenis pinjaman</span>
                        <span class="ui-dash-sec-note"><?php echo (int)$total_jenis; ?> data</span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive ui-table-scroll">
                            <table class="table table-hover table-sm align-middle mb-0 js-datatable"
                                   data-dt-page-length="10"
                                   data-dt-order="2,asc"
                                   data-dt-filter="6"
                                   data-empty-message="Belum ada jenis pinjaman.">
                                <thead>
                                    <tr>
                                        <th class="text-muted">#</th>
                                        <th>Kode</th>
                                        <th>Nama jenis</th>
                                        <th class="text-end">Maksimal</th>
                                        <th>Bunga / tenor</th>
                                        <th>Frekuensi</th>
                                        <th>Status</th>
                                        <th class="text-end no-sort">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($jenis_pinjaman as $index => $jenis):
                                    $isAktif = ($jenis['status'] ?? '') === 'aktif';
                                    $isGadai = !empty($jenis['memerlukan_jaminan']);
                                ?>
                                    <tr>
                                        <td class="text-muted"><?php echo (int)($index + 1); ?></td>
                                        <td><span class="ui-jpin-kode"><?php echo htmlspecialchars($jenis['kode_jenis']); ?></span></td>
                                        <td>
                                            <strong class="ui-jpin-name"><?php echo htmlspecialchars($jenis['nama_jenis']); ?></strong>
                                            <?php if ($isGadai): ?>
                                            <small class="ui-jpin-tag is-gadai"><i class="ti ti-building-warehouse" aria-hidden="true"></i>Gadai</small>
                                            <?php endif; ?>
                                            <?php if (trim((string)($jenis['deskripsi'] ?? '')) !== ''): ?>
                                            <small class="ui-jpin-desc"><?php echo ui_cell_ellipsis((string)$jenis['deskripsi'], 48); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end fw-semibold ui-jpin-money"><?php echo formatRupiah($jenis['maksimal_pinjaman']); ?></td>
                                        <td>
                                            <?php if (empty($jenis['tarif'])): ?>
                                            <span class="badge bg-secondary"><?php echo htmlspecialchars(labelBunga($jenis['bunga'], 'tahunan')); ?> lama</span>
                                            <small class="ui-jpin-warn d-block">Belum ada tarif flat</small>
                                            <?php else: ?>
                                            <div class="ui-jpin-tarif-badges">
                                                <?php foreach ($jenis['tarif'] as $tenor => $bunga): ?>
                                                <span class="ui-jpin-tarif-badge"><?php echo (int)$tenor; ?> bln · <?php echo htmlspecialchars(labelBunga($bunga, 'flat')); ?></span>
                                                <?php endforeach; ?>
                                            </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="ui-jpin-frek-badges">
                                            <?php foreach (explode(',', (string)($jenis['frekuensi_diizinkan'] ?? 'bulanan')) as $f):
                                                $f = trim($f);
                                                if ($f === '') continue;
                                            ?>
                                                <span class="badge bg-info-subtle text-info"><?php echo htmlspecialchars(labelFrekuensi($f)); ?></span>
                                            <?php endforeach; ?>
                                            </div>
                                        </td>
                                        <td data-filter="<?php echo $isAktif ? 'Aktif' : 'Nonaktif'; ?>">
                                            <span class="badge <?php echo $isAktif ? 'bg-success' : 'bg-secondary'; ?>">
                                                <?php echo $isAktif ? 'Aktif' : 'Nonaktif'; ?>
                                            </span>
                                        </td>
                                        <td class="table-actions text-end">
                                            <?php echo ui_action_menu([
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
                                                        'data-maksimal' => (string)$jenis['maksimal_pinjaman'],
                                                        'data-tarif' => json_encode((object)$jenis['tarif'], JSON_UNESCAPED_UNICODE),
                                                        'data-frekuensi' => $jenis['frekuensi_diizinkan'] ?? 'bulanan',
                                                        'data-jaminan' => $isGadai ? '1' : '0',
                                                        'data-deskripsi' => (string)($jenis['deskripsi'] ?? ''),
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
                                            ]); ?>
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

    <div class="modal fade ui-jpin-modal" id="addModal" tabindex="-1" aria-labelledby="addModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
            <div class="modal-content">
                <form method="POST" class="form-jenis-pinjaman ui-jpin-form-root" data-jpin-form="add" novalidate><?php echo layananToken(); ?>
                    <div class="modal-header">
                        <div>
                            <p class="ui-jpin-modal-eyebrow mb-0">Master pinjaman</p>
                            <h2 class="modal-title h5" id="addModalLabel">Tambah jenis pinjaman</h2>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                    </div>
                    <div class="modal-body">
                        <div class="ui-jpin-form">
                            <section class="ui-jpin-section">
                                <header class="ui-jpin-section-head">
                                    <i class="ti ti-id" aria-hidden="true"></i>
                                    <div>
                                        <strong>Identitas produk</strong>
                                        <span>Kode unik dan plafon maksimal</span>
                                    </div>
                                </header>
                                <div class="ui-jpin-field-grid">
                                    <div class="ui-jpin-field">
                                        <label class="form-label" for="add_kode">Kode jenis *</label>
                                        <div class="ui-jpin-kode-wrap">
                                            <span class="ui-jpin-kode-prefix">KODE</span>
                                            <input type="text" name="kode_jenis" id="add_kode" class="form-control ui-jpin-kode-input"
                                                   required placeholder="UMUM" maxlength="10" autocomplete="off" data-jpin="kode">
                                        </div>
                                    </div>
                                    <div class="ui-jpin-field">
                                        <label class="form-label" for="add_nama">Nama jenis *</label>
                                        <input type="text" name="nama_jenis" id="add_nama" class="form-control" required
                                               placeholder="Pinjaman Umum" data-jpin="nama">
                                    </div>
                                </div>
                                <div class="ui-jpin-field">
                                    <label class="form-label" for="add_maksimal_disp">Maksimal pinjaman *</label>
                                    <div class="input-group ui-jpin-money-group">
                                        <span class="input-group-text">Rp</span>
                                        <input type="text" id="add_maksimal_disp" class="form-control form-control-lg"
                                               inputmode="numeric" autocomplete="off" placeholder="1.000.000" required data-jpin-money>
                                    </div>
                                    <input type="hidden" name="maksimal_pinjaman" id="add_maksimal" value="1000000">
                                    <div class="ui-tx-chips ui-jpin-chips" data-jpin-chips>
                                        <button type="button" class="ui-tx-chip" data-amount="1000000">1 jt</button>
                                        <button type="button" class="ui-tx-chip" data-amount="5000000">5 jt</button>
                                        <button type="button" class="ui-tx-chip" data-amount="10000000">10 jt</button>
                                        <button type="button" class="ui-tx-chip" data-amount="25000000">25 jt</button>
                                    </div>
                                </div>
                                <div class="ui-jpin-field">
                                    <label class="form-label" for="add_deskripsi">Deskripsi</label>
                                    <textarea name="deskripsi" id="add_deskripsi" class="form-control" rows="2"
                                              placeholder="Opsional — catatan untuk petugas"></textarea>
                                </div>
                            </section>

                            <?php $tarif_prefix = 'add'; include __DIR__ . '/../includes/form_tarif_pinjaman.php'; ?>
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

    <div class="modal fade ui-jpin-modal is-edit" id="editModal" tabindex="-1" aria-labelledby="editModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
            <div class="modal-content">
                <form method="POST" class="form-jenis-pinjaman ui-jpin-form-root" data-jpin-form="edit" novalidate><?php echo layananToken(); ?>
                    <div class="modal-header">
                        <div>
                            <p class="ui-jpin-modal-eyebrow mb-0">Ubah produk</p>
                            <h2 class="modal-title h5" id="editModalLabel">Edit jenis pinjaman</h2>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="id" id="edit_id">
                        <div class="ui-jpin-form">
                            <section class="ui-jpin-section">
                                <header class="ui-jpin-section-head">
                                    <i class="ti ti-id" aria-hidden="true"></i>
                                    <div>
                                        <strong>Identitas produk</strong>
                                        <span>Perbarui plafon, status, atau nama</span>
                                    </div>
                                </header>
                                <div class="ui-jpin-field-grid">
                                    <div class="ui-jpin-field">
                                        <label class="form-label" for="edit_kode">Kode jenis *</label>
                                        <div class="ui-jpin-kode-wrap">
                                            <span class="ui-jpin-kode-prefix">KODE</span>
                                            <input type="text" name="kode_jenis" id="edit_kode" class="form-control ui-jpin-kode-input"
                                                   required maxlength="10" autocomplete="off" data-jpin="kode">
                                        </div>
                                    </div>
                                    <div class="ui-jpin-field">
                                        <label class="form-label" for="edit_nama">Nama jenis *</label>
                                        <input type="text" name="nama_jenis" id="edit_nama" class="form-control" required data-jpin="nama">
                                    </div>
                                </div>
                                <div class="ui-jpin-field">
                                    <label class="form-label" for="edit_maksimal_disp">Maksimal pinjaman *</label>
                                    <div class="input-group ui-jpin-money-group">
                                        <span class="input-group-text">Rp</span>
                                        <input type="text" id="edit_maksimal_disp" class="form-control form-control-lg"
                                               inputmode="numeric" autocomplete="off" placeholder="1.000.000" required data-jpin-money>
                                    </div>
                                    <input type="hidden" name="maksimal_pinjaman" id="edit_maksimal" value="0">
                                    <div class="ui-tx-chips ui-jpin-chips" data-jpin-chips>
                                        <button type="button" class="ui-tx-chip" data-amount="1000000">1 jt</button>
                                        <button type="button" class="ui-tx-chip" data-amount="5000000">5 jt</button>
                                        <button type="button" class="ui-tx-chip" data-amount="10000000">10 jt</button>
                                        <button type="button" class="ui-tx-chip" data-amount="25000000">25 jt</button>
                                    </div>
                                </div>
                                <div class="ui-jpin-field">
                                    <label class="form-label" for="edit_deskripsi">Deskripsi</label>
                                    <textarea name="deskripsi" id="edit_deskripsi" class="form-control" rows="2"></textarea>
                                </div>
                                <div class="ui-jpin-field">
                                    <span class="form-label d-block">Status</span>
                                    <div class="ui-jpin-status" role="radiogroup" aria-label="Status jenis">
                                        <label class="ui-jpin-status-opt">
                                            <input type="radio" name="status" id="edit_status_aktif" value="aktif" checked>
                                            <span><i class="ti ti-circle-check" aria-hidden="true"></i>Aktif</span>
                                        </label>
                                        <label class="ui-jpin-status-opt">
                                            <input type="radio" name="status" id="edit_status_nonaktif" value="nonaktif">
                                            <span><i class="ti ti-circle-x" aria-hidden="true"></i>Nonaktif</span>
                                        </label>
                                    </div>
                                </div>
                            </section>

                            <?php $tarif_prefix = 'edit'; include __DIR__ . '/../includes/form_tarif_pinjaman.php'; ?>
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

    <div class="modal fade ui-jpin-modal is-danger" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST" id="deleteForm"><?php echo layananToken(); ?>
                    <div class="modal-header">
                        <h2 class="modal-title h5" id="deleteModalLabel">Hapus jenis pinjaman</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="id" id="delete_id">
                        <input type="hidden" name="delete" value="1">
                        <div class="ui-jpin-delete">
                            <i class="ti ti-alert-triangle" aria-hidden="true"></i>
                            <div>
                                <p class="mb-1">Hapus jenis pinjaman berikut?</p>
                                <strong id="delete_nama"></strong>
                                <p class="text-muted small mb-0 mt-2">Hanya bisa dihapus jika belum dipakai di pengajuan atau pinjaman.</p>
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

        var BUNGA_MAKS = <?php echo (int)BUNGA_MAKS; ?>;

        function digits(v) { return String(v == null ? '' : v).replace(/\D+/g, ''); }
        function fmtNum(n) {
            n = Math.round(Number(n) || 0);
            return n === 0 ? '' : n.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        }
        function setMaksimal(form, value) {
            var raw = Math.max(0, Math.round(Number(value) || 0));
            var hidden = form.querySelector('[name="maksimal_pinjaman"]');
            var disp = form.querySelector('[data-jpin-money]');
            if (hidden) hidden.value = String(raw);
            if (disp) disp.value = fmtNum(raw);
        }

        window.tambahBarisTarif = function (prefix, tenor, bunga) {
            var tbody = document.getElementById(prefix + '_tarif_rows');
            if (!tbody) return;
            var tr = document.createElement('tr');
            tr.innerHTML =
                '<td><div class="input-group input-group-sm"><input type="number" name="tarif_tenor[]" class="form-control" required min="1" max="36" step="1" placeholder="1–36"><span class="input-group-text">bulan</span></div></td>' +
                '<td><div class="input-group input-group-sm"><input type="number" name="tarif_bunga[]" class="form-control" required min="0" max="' + BUNGA_MAKS + '" step="0.01" placeholder="0"><span class="input-group-text">%</span></div></td>' +
                '<td class="table-actions text-end"><button type="button" class="btn btn-sm btn-outline-danger" onclick="hapusBarisTarif(this)" title="Hapus"><i class="ti ti-x" aria-hidden="true"></i></button></td>';
            tr.querySelector('input[name="tarif_tenor[]"]').value = tenor || '';
            tr.querySelector('input[name="tarif_bunga[]"]').value = (bunga !== undefined && bunga !== null && bunga !== '') ? bunga : '';
            tbody.appendChild(tr);
        };

        window.hapusBarisTarif = function (btn) {
            var tbody = btn.closest('tbody');
            if (tbody && tbody.rows.length > 1) btn.closest('tr').remove();
        };

        function isiFormTarif(prefix, tarif, frekuensi) {
            var tbody = document.getElementById(prefix + '_tarif_rows');
            if (!tbody) return;
            tbody.innerHTML = '';
            var tenors = Object.keys(tarif || {});
            if (tenors.length === 0) tambahBarisTarif(prefix);
            tenors.forEach(function (t) { tambahBarisTarif(prefix, t, tarif[t]); });
            var list = frekuensi || [];
            var b = document.getElementById(prefix + '_frek_bulanan');
            var m = document.getElementById(prefix + '_frek_mingguan');
            var d = document.getElementById(prefix + '_frek_dua_mingguan');
            if (b) b.checked = list.indexOf('bulanan') !== -1;
            if (m) m.checked = list.indexOf('mingguan') !== -1;
            if (d) d.checked = list.indexOf('dua_mingguan') !== -1;
        }

        function aturFormGadai(prefix) {
            var jamin = document.getElementById(prefix + '_jaminan');
            var checked = !!(jamin && jamin.checked);
            var tarifSection = document.getElementById(prefix + '_tarif_section');
            var frekuensiSection = document.getElementById(prefix + '_frekuensi_section');
            if (tarifSection) tarifSection.hidden = checked;
            if (frekuensiSection) frekuensiSection.hidden = checked;
            if (tarifSection) {
                tarifSection.querySelectorAll('input').forEach(function (input) {
                    input.required = !checked;
                    input.disabled = checked;
                });
            }
            if (frekuensiSection) {
                frekuensiSection.querySelectorAll('input').forEach(function (input) {
                    input.disabled = checked;
                });
            }
        }

        function validasiFormTarif(form) {
            var jamin = form.querySelector('input[name="memerlukan_jaminan"]');
            if (jamin && jamin.checked) return true;
            if (form.querySelectorAll('input[name="frekuensi[]"]:checked').length === 0) {
                alert('Pilih minimal satu frekuensi angsuran.');
                return false;
            }
            var seen = {};
            var tenors = form.querySelectorAll('input[name="tarif_tenor[]"]');
            for (var i = 0; i < tenors.length; i++) {
                if (!tenors[i].disabled && seen[tenors[i].value]) {
                    alert('Tenor ' + tenors[i].value + ' bulan diisi lebih dari sekali.');
                    return false;
                }
                if (!tenors[i].disabled) seen[tenors[i].value] = true;
            }
            return true;
        }

        function bindForm(form) {
            if (!form || form.dataset.jpinReady) return;
            form.dataset.jpinReady = '1';
            var prefix = form.getAttribute('data-jpin-form') || 'add';

            var kode = form.querySelector('[data-jpin="kode"]');
            if (kode) {
                kode.addEventListener('input', function () {
                    var start = kode.selectionStart;
                    kode.value = kode.value.toUpperCase().replace(/[^A-Z0-9_-]/g, '');
                    try { kode.setSelectionRange(start, start); } catch (e) {}
                });
            }

            var disp = form.querySelector('[data-jpin-money]');
            if (disp) {
                disp.addEventListener('input', function () {
                    setMaksimal(form, digits(disp.value));
                    var pos = disp.value.length;
                    try { disp.setSelectionRange(pos, pos); } catch (e) {}
                });
                disp.addEventListener('blur', function () { setMaksimal(form, digits(disp.value)); });
            }
            form.querySelectorAll('[data-jpin-chips] .ui-tx-chip').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    setMaksimal(form, btn.getAttribute('data-amount'));
                    form.querySelectorAll('[data-jpin-chips] .ui-tx-chip').forEach(function (c) { c.classList.remove('is-active'); });
                    btn.classList.add('is-active');
                });
            });

            var jamin = document.getElementById(prefix + '_jaminan');
            if (jamin) {
                jamin.addEventListener('change', function () { aturFormGadai(prefix); });
            }

            form.addEventListener('submit', function (e) {
                if (!validasiFormTarif(form)) e.preventDefault();
            });
        }

        document.querySelectorAll('.ui-jpin-form-root').forEach(bindForm);
        isiFormTarif('add', {}, ['bulanan']);
        aturFormGadai('add');
        setMaksimal(document.querySelector('[data-jpin-form="add"]'), 1000000);

        var addModal = document.getElementById('addModal');
        if (addModal) {
            addModal.addEventListener('show.bs.modal', function () {
                var form = addModal.querySelector('.ui-jpin-form-root');
                if (!form) return;
                form.reset();
                isiFormTarif('add', {}, ['bulanan']);
                aturFormGadai('add');
                setMaksimal(form, 1000000);
                form.querySelectorAll('.ui-tx-chip.is-active').forEach(function (c) { c.classList.remove('is-active'); });
            });
        }

        var editModal = document.getElementById('editModal');
        if (editModal) {
            editModal.addEventListener('show.bs.modal', function (event) {
                var button = event.relatedTarget;
                var form = editModal.querySelector('.ui-jpin-form-root');
                if (!button || !form) return;
                document.getElementById('edit_id').value = button.getAttribute('data-id') || '';
                document.getElementById('edit_kode').value = (button.getAttribute('data-kode') || '').toUpperCase();
                document.getElementById('edit_nama').value = button.getAttribute('data-nama') || '';
                document.getElementById('edit_deskripsi').value = button.getAttribute('data-deskripsi') || '';
                setMaksimal(form, button.getAttribute('data-maksimal') || 0);
                var tarif = {};
                try { tarif = JSON.parse(button.getAttribute('data-tarif') || '{}') || {}; } catch (e) { tarif = {}; }
                isiFormTarif('edit', tarif, (button.getAttribute('data-frekuensi') || 'bulanan').split(','));
                var jamin = document.getElementById('edit_jaminan');
                if (jamin) jamin.checked = button.getAttribute('data-jaminan') === '1';
                aturFormGadai('edit');
                var st = button.getAttribute('data-status') || 'aktif';
                var aktif = document.getElementById('edit_status_aktif');
                var non = document.getElementById('edit_status_nonaktif');
                if (aktif) aktif.checked = st === 'aktif';
                if (non) non.checked = st !== 'aktif';
                form.querySelectorAll('.ui-tx-chip.is-active').forEach(function (c) { c.classList.remove('is-active'); });
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
