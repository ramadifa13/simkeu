<?php
/**
 * Single implementation — Persetujuan pengajuan (Admin mutate, Teller read-only).
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/auth.php';

if (!isLoggedIn() || (!isAdmin() && !isTeller())) {
    http_response_code(403);
    exit('Akses ditolak.');
}

$bolehMutasi = can('pengajuan.setujui');

// Handle form actions (Admin only)
if ($_POST) {
    if (!$bolehMutasi) {
        http_response_code(403);
        exit('Akses ditolak. Hanya Admin yang dapat memutuskan pengajuan.');
    }
    if (isset($_POST['approve'])) {
        $id = $_POST['id'];
        $catatan_admin = $_POST['catatan_admin'] ?? '';
        
        try {
            $db->beginTransaction();
            
            // Update pengajuan status
            $query_update = "UPDATE pengajuan_pinjaman SET status = 'disetujui', 
                            catatan_admin = :catatan_admin, admin_id = :admin_id,
                            tanggal_persetujuan = NOW() WHERE id = :id AND status = 'menunggu'";
            $stmt_update = $db->prepare($query_update);
            $stmt_update->bindParam(':id', $id);
            $stmt_update->bindParam(':catatan_admin', $catatan_admin);
            $stmt_update->bindParam(':admin_id', $_SESSION['user_id']);
            
            if ($stmt_update->execute()) {
                // Persetujuan TIDAK membuat pinjaman/jadwal angsuran. Pinjaman dan jadwal dibuat
                // saat Teller memproses pencairan (teller/pencairan.php), tanggal dihitung dari pencairan.
                if ($stmt_update->rowCount() < 1) {
                    throw new Exception("Data pengajuan tidak ditemukan atau sudah diproses");
                }
                $db->commit();
                $_SESSION['success'] = "Pengajuan disetujui. Menunggu Teller memproses pencairan"; 
            } else {
                throw new Exception("Gagal mengupdate status pengajuan");
            }
        } catch (Exception $e) {
            $db->rollBack();
            $_SESSION['error'] = "Gagal menyetujui pengajuan: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['reject'])) {
        $id = $_POST['id'];
        $catatan_admin = $_POST['catatan_admin'] ?? '';
        
        $query = "UPDATE pengajuan_pinjaman SET status = 'ditolak', 
                  catatan_admin = :catatan_admin, admin_id = :admin_id,
                  tanggal_persetujuan = NOW() WHERE id = :id AND status = 'menunggu'";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':catatan_admin', $catatan_admin);
        $stmt->bindParam(':admin_id', $_SESSION['user_id']);
        
        if ($stmt->execute() && $stmt->rowCount() === 1) {
            $anggotaTolak = $db->prepare("SELECT anggota_id FROM pengajuan_pinjaman WHERE id = ?");
            $anggotaTolak->execute([$id]);
            $anggotaTolakId = (int) $anggotaTolak->fetchColumn();
            if ($anggotaTolakId > 0) {
                bersihkanKodeAnggotaJaminanJikaTidakCair($db, $anggotaTolakId);
            }
            $_SESSION['success'] = "Pengajuan pinjaman berhasil ditolak";
        } else {
            $_SESSION['error'] = "Gagal menolak pengajuan";
        }
    }
    
    if (isset($_POST['delete'])) {
        $id = $_POST['id'];
        $anggotaHapus = $db->prepare("SELECT anggota_id FROM pengajuan_pinjaman WHERE id = ?");
        $anggotaHapus->execute([$id]);
        $anggotaHapusId = (int) $anggotaHapus->fetchColumn();

        $query = "DELETE FROM pengajuan_pinjaman WHERE id = :id AND status IN ('menunggu','ditolak') AND NOT EXISTS (SELECT 1 FROM pinjaman WHERE pengajuan_id = pengajuan_pinjaman.id)";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $id);
        
        if ($stmt->execute() && $stmt->rowCount() === 1) {
            if ($anggotaHapusId > 0) {
                bersihkanKodeAnggotaJaminanJikaTidakCair($db, $anggotaHapusId);
            }
            $_SESSION['success'] = "Pengajuan pinjaman berhasil dihapus";
        } else {
            $_SESSION['error'] = "Gagal menghapus pengajuan";
        }
    }
}

// Daftar semua pengajuan (filter/sort di DataTables client)
$query = "SELECT pp.*, a.nama_lengkap, a.foto_ktp, a.foto_kk, a.foto_ktp_pasangan,
          CASE WHEN jp.memerlukan_jaminan = 1 THEN COALESCE(a.kode_anggota_jaminan, a.kode_anggota) ELSE a.kode_anggota END AS kode_anggota,
          jp.nama_jenis, jp.memerlukan_jaminan,
          (SELECT COUNT(*) FROM pinjaman pj WHERE pj.pengajuan_id = pp.id) AS sudah_cair,
          jp.maksimal_pinjaman, u.nama_lengkap as admin_name
          FROM pengajuan_pinjaman pp
          JOIN anggota a ON pp.anggota_id = a.id
          JOIN jenis_pinjaman jp ON pp.jenis_pinjaman_id = jp.id
          LEFT JOIN users u ON pp.admin_id = u.id
          ORDER BY pp.tanggal_pengajuan DESC";
$stmt = $db->prepare($query);
$stmt->execute();
$pengajuan_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

$query_stats = "SELECT
    COUNT(*) as total_pengajuan,
    COUNT(CASE WHEN status = 'menunggu' THEN 1 END) as menunggu,
    COUNT(CASE WHEN status = 'disetujui' AND NOT EXISTS (SELECT 1 FROM pinjaman pj WHERE pj.pengajuan_id = pengajuan_pinjaman.id) THEN 1 END) as disetujui,
    COUNT(CASE WHEN status = 'ditolak' THEN 1 END) as ditolak
    FROM pengajuan_pinjaman";
$stmt_stats = $db->prepare($query_stats);
$stmt_stats->execute();
$stats = $stmt_stats->fetch(PDO::FETCH_ASSOC);

$statusClassMap = [
    'menunggu' => 'warning',
    'disetujui' => 'success',
    'ditolak' => 'danger',
    'batal_cair' => 'secondary',
    'dicairkan' => 'info',
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Persetujuan Pinjaman - <?php echo APP_NAME; ?></title>
    <?php $uiAssetPrefix = '../'; include __DIR__ . '/layout/assets.php'; ?>
</head>
<body>
    <?php include __DIR__ . '/layout/shell_start.php'; ?>
            <div class="ui-tx-page">
                <header class="ui-dash-header">
                    <div class="ui-dash-header-icon ui-tx-icon-approve" aria-hidden="true"><i class="ti ti-circle-check"></i></div>
                    <div class="ui-dash-header-copy">
                        <p class="ui-dash-eyebrow mb-0">Pinjaman</p>
                        <h1 class="ui-dash-title">Persetujuan Pinjaman</h1>
                        <p class="ui-dash-meta mb-0">Review dan putuskan pengajuan — pencairan diproses Teller</p>
                    </div>
                    <div class="ui-dash-header-actions">
                        <a href="pencairan.php" class="btn ui-tx-btn-approve-ghost">
                            <i class="ti ti-cash me-1" aria-hidden="true"></i>
                            <span class="d-none d-sm-inline">Ke pencairan</span><span class="d-sm-none">Cair</span>
                        </a>
                    </div>
                </header>

                <?php if (!$bolehMutasi): ?>
                <div class="alert alert-info ui-ps-readonly">
                    <i class="ti ti-eye me-1" aria-hidden="true"></i>
                    Mode lihat saja. Persetujuan/penolakan hanya dilakukan Admin.
                </div>
                <?php endif; ?>

                <?php ui_flash(); ?>

                <div class="ui-ps-kpi">
                <?php ui_stat_grid([
                    ['label' => 'Total pengajuan', 'value' => (int)$stats['total_pengajuan'], 'tone' => 'primary', 'icon' => 'ti ti-file-text', 'hint' => 'Semua'],
                    ['label' => 'Menunggu', 'value' => (int)$stats['menunggu'], 'tone' => 'warning', 'icon' => 'ti ti-clock', 'hint' => 'Perlu putusan'],
                    ['label' => 'Disetujui', 'value' => (int)$stats['disetujui'], 'tone' => 'success', 'icon' => 'ti ti-circle-check', 'hint' => 'Siap cair'],
                    ['label' => 'Ditolak', 'value' => (int)$stats['ditolak'], 'tone' => 'danger', 'icon' => 'ti ti-circle-x', 'hint' => 'Ditolak'],
                ]); ?>
                </div>

                <div class="card mt-3" id="riwayat">
                    <div class="card-header ui-dash-sec">
                        <span class="ui-dash-sec-title"><i class="ti ti-list-check" aria-hidden="true"></i>Daftar pengajuan</span>
                        <span class="ui-dash-sec-note"><?= (int)count($pengajuan_list) ?> data</span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive ui-table-scroll">
                            <table class="table table-hover align-middle mb-0 js-datatable"
                                   data-dt-page-length="10"
                                   data-dt-order="0,asc;1,desc"
                                   data-dt-filter="6"
                                   data-dt-date-col="1">
                                <thead>
                                    <tr>
                                        <th class="dt-prio">Prio</th>
                                        <th>Tanggal</th>
                                        <th>Kode</th>
                                        <th>Anggota</th>
                                        <th>Jenis</th>
                                        <th class="text-end">Jumlah</th>
                                        <th>Status</th>
                                        <th class="text-end">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pengajuan_list as $pengajuan):
                                        $ts = strtotime((string)($pengajuan['tanggal_pengajuan'] ?? ''));
                                        $orderTs = $ts ? date('Y-m-d H:i:s', $ts) : '';
                                        $day = $ts ? date('Y-m-d', $ts) : '';
                                        $statusTampil = $pengajuan['status'] === 'dicairkan' ? 'disetujui' : $pengajuan['status'];
                                        $badge = $statusClassMap[$statusTampil] ?? 'secondary';
                                        $statusLabel = $statusTampil === 'batal_cair' ? 'Batal Cair' : ucfirst((string)$statusTampil);
                                        $prio = ($bolehMutasi && $pengajuan['status'] === 'menunggu') ? 0 : 9;
                                        $dokumen_detail = [
                                            'foto_ktp' => urlDokumenAnggota($pengajuan['foto_ktp']),
                                            'foto_kk' => urlDokumenAnggota($pengajuan['foto_kk']),
                                            'foto_ktp_pasangan' => urlDokumenAnggota($pengajuan['foto_ktp_pasangan']),
                                        ];
                                    ?>
                                    <tr<?= $prio < 9 ? ' class="ui-row-action"' : '' ?>>
                                        <td><?= (int)$prio ?></td>
                                        <td data-order="<?= htmlspecialchars($orderTs) ?>" data-date="<?= htmlspecialchars($day) ?>">
                                            <?= htmlspecialchars(formatTanggalUi($pengajuan['tanggal_pengajuan'], true, true)) ?>
                                        </td>
                                        <td><code><?= htmlspecialchars($pengajuan['kode_pengajuan']) ?></code></td>
                                        <td>
                                            <div class="fw-semibold"><?= htmlspecialchars($pengajuan['nama_lengkap']) ?></div>
                                            <small class="text-muted"><?= htmlspecialchars((string)$pengajuan['kode_anggota']) ?></small>
                                        </td>
                                        <td>
                                            <div><?= htmlspecialchars($pengajuan['nama_jenis']) ?></div>
                                            <small class="text-muted"><?= htmlspecialchars(labelTenorRow($pengajuan)) ?> · <?= htmlspecialchars(labelBungaRow($pengajuan)) ?></small>
                                        </td>
                                        <td class="text-end fw-semibold"><?= formatRupiah($pengajuan['jumlah_pinjaman']) ?></td>
                                        <td data-filter="<?= htmlspecialchars($statusLabel) ?>">
                                            <span class="badge bg-<?= htmlspecialchars($badge) ?>"><?= htmlspecialchars($statusLabel) ?></span>
                                            <?php if (!empty($pengajuan['admin_name'])): ?>
                                            <div class="small text-muted mt-1">Oleh: <?= htmlspecialchars($pengajuan['admin_name']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end table-actions">
                                            <?php
                                            $aksiPersetujuan = [
                                                [
                                                    'label' => 'Detail',
                                                    'icon' => 'ti ti-eye',
                                                    'attrs' => [
                                                        'data-bs-toggle' => 'modal',
                                                        'data-bs-target' => '#detailModal',
                                                        'data-kode' => $pengajuan['kode_pengajuan'],
                                                        'data-nama' => $pengajuan['nama_lengkap'],
                                                        'data-kode_anggota' => $pengajuan['kode_anggota'],
                                                        'data-jenis' => $pengajuan['nama_jenis'],
                                                        'data-jumlah' => $pengajuan['jumlah_pinjaman'],
                                                        'data-tenor' => $pengajuan['tenor'],
                                                        'data-bunga' => labelBungaRow($pengajuan),
                                                        'data-tujuan' => $pengajuan['tujuan_pinjaman'],
                                                        'data-status' => $statusTampil,
                                                        'data-tanggal' => formatTanggalUi($pengajuan['tanggal_pengajuan'], true),
                                                        'data-catatan' => $pengajuan['catatan_admin'],
                                                        'data-jaminan' => !empty($pengajuan['memerlukan_jaminan']) ? '1' : '0',
                                                        'data-dokumen' => json_encode($dokumen_detail),
                                                    ],
                                                ],
                                            ];
                                            if ($bolehMutasi && $pengajuan['status'] == 'menunggu') {
                                                $aksiPersetujuan[] = [
                                                    'label' => 'Setujui',
                                                    'icon' => 'ti ti-check',
                                                    'class' => 'text-success',
                                                    'attrs' => [
                                                        'data-bs-toggle' => 'modal',
                                                        'data-bs-target' => '#approveModal',
                                                        'data-id' => (int)$pengajuan['id'],
                                                        'data-kode' => $pengajuan['kode_pengajuan'],
                                                        'data-nama' => $pengajuan['nama_lengkap'],
                                                    ],
                                                ];
                                                $aksiPersetujuan[] = [
                                                    'label' => 'Tolak',
                                                    'icon' => 'ti ti-x',
                                                    'class' => 'text-danger',
                                                    'attrs' => [
                                                        'data-bs-toggle' => 'modal',
                                                        'data-bs-target' => '#rejectModal',
                                                        'data-id' => (int)$pengajuan['id'],
                                                        'data-kode' => $pengajuan['kode_pengajuan'],
                                                        'data-nama' => $pengajuan['nama_lengkap'],
                                                    ],
                                                ];
                                            }
                                            if ($bolehMutasi) {
                                                $aksiPersetujuan[] = ['divider' => true];
                                                $aksiPersetujuan[] = [
                                                    'label' => 'Hapus',
                                                    'icon' => 'ti ti-trash',
                                                    'class' => 'text-danger',
                                                    'attrs' => [
                                                        'data-bs-toggle' => 'modal',
                                                        'data-bs-target' => '#deleteModal',
                                                        'data-id' => (int)$pengajuan['id'],
                                                        'data-kode' => $pengajuan['kode_pengajuan'],
                                                        'data-nama' => $pengajuan['nama_lengkap'],
                                                    ],
                                                ];
                                            }
                                            echo ui_action_menu($aksiPersetujuan);
                                            ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <?php include __DIR__ . '/layout/shell_end.php'; ?>

    <div class="modal fade ui-tx-modal ui-tx-modal-approve" id="detailModal" tabindex="-1" aria-labelledby="detailModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title h5" id="detailModalLabel">Detail pengajuan</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                </div>
                <div class="modal-body">
                    <div class="ui-pj-detail">
                        <div class="ui-pj-detail-hero">
                            <div class="ui-pj-detail-hero-main min-w-0">
                                <div class="ui-pj-detail-kode" id="detail_kode">—</div>
                                <div class="ui-pj-detail-nama text-truncate" id="detail_nama">—</div>
                                <div class="ui-pj-detail-meta" id="detail_kode_anggota">—</div>
                            </div>
                            <div class="ui-pj-detail-hero-status" id="detail_status">—</div>
                        </div>
                        <div class="ui-pj-detail-amount">
                            <div class="ui-pj-detail-amount-item">
                                <span class="ui-pj-detail-amount-label">Jumlah pinjaman</span>
                                <strong class="ui-pj-detail-amount-value" id="detail_jumlah">—</strong>
                            </div>
                            <div class="ui-pj-detail-amount-item">
                                <span class="ui-pj-detail-amount-label">Jenis</span>
                                <strong class="ui-pj-detail-amount-sub" id="detail_jenis">—</strong>
                            </div>
                        </div>
                        <div class="ui-pj-detail-grid">
                            <section class="ui-pj-detail-card">
                                <h3 class="ui-pj-detail-card-title"><i class="ti ti-percentage" aria-hidden="true"></i>Syarat pinjaman</h3>
                                <dl class="ui-pj-detail-dl">
                                    <div><dt>Tenor</dt><dd id="detail_tenor">—</dd></div>
                                    <div><dt>Bunga</dt><dd id="detail_bunga">—</dd></div>
                                    <div><dt>Tanggal</dt><dd id="detail_tanggal">—</dd></div>
                                </dl>
                            </section>
                            <section class="ui-pj-detail-card">
                                <h3 class="ui-pj-detail-card-title"><i class="ti ti-notes" aria-hidden="true"></i>Catatan admin</h3>
                                <p class="ui-pj-detail-tujuan mb-0" id="detail_catatan">—</p>
                            </section>
                            <section class="ui-pj-detail-card ui-pj-detail-card-wide">
                                <h3 class="ui-pj-detail-card-title"><i class="ti ti-target" aria-hidden="true"></i>Tujuan pinjaman</h3>
                                <p class="ui-pj-detail-tujuan mb-0" id="detail_tujuan">—</p>
                            </section>
                            <section class="ui-pj-detail-card ui-pj-detail-card-wide">
                                <div class="d-flex align-items-center justify-content-between gap-2 mb-2 flex-wrap">
                                    <h3 class="ui-pj-detail-card-title mb-0"><i class="ti ti-photo" aria-hidden="true"></i>Dokumen anggota</h3>
                                    <span id="detail_dokumen_status" class="badge bg-secondary">Memeriksa</span>
                                </div>
                                <p class="ui-pj-section-note mb-3" id="detail_dokumen_info"></p>
                                <div id="detail_dokumen" class="row g-3"></div>
                            </section>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Tutup</button>
                </div>
            </div>
        </div>
    </div>

    <?php if ($bolehMutasi): ?>
    <div class="modal fade ui-tx-modal ui-tx-modal-approve" id="approveModal" tabindex="-1" aria-labelledby="approveModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST"><?php echo layananToken(); ?>
                    <div class="modal-header">
                        <h2 class="modal-title h5" id="approveModalLabel">Setujui pengajuan</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="id" id="approve_id">
                        <div class="ui-ps-confirm">
                            <div class="ui-ps-confirm-label">Anggota</div>
                            <div class="ui-ps-confirm-value" id="approve_nama">—</div>
                            <div class="ui-ps-confirm-meta">Kode: <span id="approve_kode">—</span></div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="approve_catatan">Catatan (opsional)</label>
                            <textarea name="catatan_admin" id="approve_catatan" class="form-control" rows="3"
                                      placeholder="Catatan untuk anggota…"></textarea>
                        </div>
                        <div class="alert alert-info mb-0 py-2">
                            <small><i class="ti ti-info-circle me-1"></i>Setelah disetujui, menunggu Teller memproses pencairan.</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" name="approve" value="1" class="btn btn-success">
                            <i class="ti ti-check me-1" aria-hidden="true"></i>Setujui
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade ui-tx-modal ui-tx-modal-reject" id="rejectModal" tabindex="-1" aria-labelledby="rejectModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST"><?php echo layananToken(); ?>
                    <div class="modal-header">
                        <h2 class="modal-title h5" id="rejectModalLabel">Tolak pengajuan</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="id" id="reject_id">
                        <div class="ui-ps-confirm">
                            <div class="ui-ps-confirm-label">Anggota</div>
                            <div class="ui-ps-confirm-value" id="reject_nama">—</div>
                            <div class="ui-ps-confirm-meta">Kode: <span id="reject_kode">—</span></div>
                        </div>
                        <div class="mb-0">
                            <label class="form-label" for="reject_catatan">Alasan penolakan *</label>
                            <textarea name="catatan_admin" id="reject_catatan" class="form-control" rows="3"
                                      placeholder="Berikan alasan penolakan…" required></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" name="reject" value="1" class="btn btn-danger">
                            <i class="ti ti-x me-1" aria-hidden="true"></i>Tolak
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade ui-tx-modal ui-tx-modal-reject" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST"><?php echo layananToken(); ?>
                    <div class="modal-header">
                        <h2 class="modal-title h5" id="deleteModalLabel">Hapus pengajuan</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="id" id="delete_id">
                        <div class="ui-ps-confirm">
                            <div class="ui-ps-confirm-label">Anggota</div>
                            <div class="ui-ps-confirm-value" id="delete_nama">—</div>
                            <div class="ui-ps-confirm-meta">Kode: <span id="delete_kode">—</span></div>
                        </div>
                        <div class="alert alert-warning mb-0 py-2">
                            <small><i class="ti ti-alert-triangle me-1"></i>Data yang dihapus tidak dapat dikembalikan.</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" name="delete" value="1" class="btn btn-danger">
                            <i class="ti ti-trash me-1" aria-hidden="true"></i>Hapus
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script>
    (function () {
        function formatRp(n) {
            n = Math.max(0, Math.floor(Number(n) || 0));
            return 'Rp ' + n.toLocaleString('id-ID');
        }

        var detailModal = document.getElementById('detailModal');
        detailModal.addEventListener('show.bs.modal', function (event) {
            var button = event.relatedTarget;
            if (!button) return;
            document.getElementById('detail_kode').textContent = button.getAttribute('data-kode') || '—';
            document.getElementById('detail_nama').textContent = button.getAttribute('data-nama') || '—';
            document.getElementById('detail_kode_anggota').textContent = button.getAttribute('data-kode_anggota') || '—';
            document.getElementById('detail_jenis').textContent = button.getAttribute('data-jenis') || '—';
            document.getElementById('detail_jumlah').textContent = formatRp(button.getAttribute('data-jumlah'));
            document.getElementById('detail_tenor').textContent = (button.getAttribute('data-tenor') || '—') + ' bulan';
            document.getElementById('detail_bunga').textContent = button.getAttribute('data-bunga') || '—';

            var status = button.getAttribute('data-status') || '-';
            var statusClass = { menunggu: 'warning text-dark', disetujui: 'success', ditolak: 'danger', batal_cair: 'secondary', dicairkan: 'info' };
            var statusLabel = String(status).replace(/_/g, ' ');
            document.getElementById('detail_status').innerHTML = '<span class="badge bg-' + (statusClass[status] || 'secondary') + '">' +
                statusLabel.charAt(0).toUpperCase() + statusLabel.slice(1) + '</span>';

            document.getElementById('detail_tujuan').textContent = button.getAttribute('data-tujuan') || '—';
            document.getElementById('detail_tanggal').textContent = button.getAttribute('data-tanggal') || '—';
            document.getElementById('detail_catatan').textContent = button.getAttribute('data-catatan') || '—';

            var denganJaminan = button.getAttribute('data-jaminan') === '1';
            var dokumen = {};
            try { dokumen = JSON.parse(button.getAttribute('data-dokumen') || '{}'); } catch (e) { dokumen = {}; }
            var daftar = denganJaminan
                ? [['foto_ktp', 'Foto KTP']]
                : [['foto_ktp', 'Foto KTP'], ['foto_kk', 'Foto Kartu Keluarga (KK)'], ['foto_ktp_pasangan', 'Foto KTP Suami/Istri']];
            var wadah = document.getElementById('detail_dokumen');
            wadah.replaceChildren();
            var lengkap = true;
            daftar.forEach(function (item) {
                var url = dokumen[item[0]] || '';
                if (!url) lengkap = false;
                var kolom = document.createElement('div');
                kolom.className = 'col-md-' + (daftar.length === 1 ? '12' : '4');
                var kartu = document.createElement('div');
                kartu.className = 'ui-ps-doc';
                var judul = document.createElement('div');
                judul.className = 'ui-ps-doc-title';
                judul.textContent = item[1];
                kartu.appendChild(judul);
                if (url) {
                    var tautan = document.createElement('a');
                    tautan.href = url;
                    tautan.target = '_blank';
                    tautan.rel = 'noopener noreferrer';
                    tautan.className = 'ui-ps-doc-link';
                    var gambar = document.createElement('img');
                    gambar.src = url;
                    gambar.alt = item[1];
                    gambar.className = 'ui-ps-doc-img';
                    tautan.appendChild(gambar);
                    kartu.appendChild(tautan);
                } else {
                    var kosong = document.createElement('div');
                    kosong.className = 'ui-ps-doc-empty';
                    kosong.innerHTML = '<i class="ti ti-photo-off" aria-hidden="true"></i><span>' + item[1] + ' belum tersedia</span>';
                    kartu.appendChild(kosong);
                }
                kolom.appendChild(kartu);
                wadah.appendChild(kolom);
            });
            document.getElementById('detail_dokumen_info').textContent = denganJaminan
                ? 'Pinjaman dengan jaminan: dokumen wajib berupa KTP.'
                : 'Pinjaman tanpa jaminan: dokumen wajib KTP, KK, dan KTP suami/istri.';
            var statusDokumen = document.getElementById('detail_dokumen_status');
            statusDokumen.className = 'badge bg-' + (lengkap ? 'success' : 'danger');
            statusDokumen.textContent = lengkap ? 'Dokumen lengkap' : 'Dokumen belum lengkap';
        });

        var approveModal = document.getElementById('approveModal');
        if (approveModal) approveModal.addEventListener('show.bs.modal', function (event) {
            var button = event.relatedTarget;
            if (!button) return;
            document.getElementById('approve_id').value = button.getAttribute('data-id') || '';
            document.getElementById('approve_nama').textContent = button.getAttribute('data-nama') || '—';
            document.getElementById('approve_kode').textContent = button.getAttribute('data-kode') || '—';
            var cat = document.getElementById('approve_catatan');
            if (cat) cat.value = '';
        });

        var rejectModal = document.getElementById('rejectModal');
        if (rejectModal) rejectModal.addEventListener('show.bs.modal', function (event) {
            var button = event.relatedTarget;
            if (!button) return;
            document.getElementById('reject_id').value = button.getAttribute('data-id') || '';
            document.getElementById('reject_nama').textContent = button.getAttribute('data-nama') || '—';
            document.getElementById('reject_kode').textContent = button.getAttribute('data-kode') || '—';
            var cat = document.getElementById('reject_catatan');
            if (cat) cat.value = '';
        });

        var deleteModal = document.getElementById('deleteModal');
        if (deleteModal) deleteModal.addEventListener('show.bs.modal', function (event) {
            var button = event.relatedTarget;
            if (!button) return;
            document.getElementById('delete_id').value = button.getAttribute('data-id') || '';
            document.getElementById('delete_nama').textContent = button.getAttribute('data-nama') || '—';
            document.getElementById('delete_kode').textContent = button.getAttribute('data-kode') || '—';
        });
    })();
    </script>
</body>
</html>
