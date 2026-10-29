<?php
require_once '../includes/auth.php';
require_once '../config/config.php';

if (!isAdmin() || !can('koreksi.mutate')) {
    http_response_code(403);
    exit('Akses ditolak. Koreksi khusus Admin.');
}

schemaPastikanLogKoreksi($db);

// Handle form actions
if ($_POST) {
    if (isset($_POST['koreksi'])) {
        require_can('koreksi.mutate');
        $transaksi_id = $_POST['transaksi_id'];
        $jumlah_baru = $_POST['jumlah_baru'];
        $keterangan_koreksi = $_POST['keterangan_koreksi'];
        
        try {
            if (!is_scalar($jumlah_baru) || !preg_match('/^\d+(?:\.\d{1,2})?$/D', (string)$jumlah_baru) || !is_finite((float)$jumlah_baru) || (float)$jumlah_baru < 0 || (float)$jumlah_baru > 999999999999) {
                throw new Exception('Nominal koreksi tidak valid.');
            }
            $db->beginTransaction();
            
            // Get original transaction data
            $query_transaksi = "SELECT * FROM transaksi WHERE id = :id FOR UPDATE";
            $stmt_transaksi = $db->prepare($query_transaksi);
            $stmt_transaksi->bindParam(':id', $transaksi_id);
            $stmt_transaksi->execute();
            $transaksi = $stmt_transaksi->fetch(PDO::FETCH_ASSOC);
            
            if (!$transaksi) {
                throw new Exception("Transaksi tidak ditemukan");
            }
            
            $journal=$db->prepare('SELECT kode_jenis FROM jenis_simpanan WHERE id=?');
            $journal->execute([$transaksi['jenis_simpanan_id']]);
            if($journal->fetchColumn()==='SHU') throw new Exception('Jurnal SHU terkunci. Gunakan Koreksi pada riwayat pembayaran SHU agar hak anggota dan jurnal pembalik tetap sesuai.');
            $selisih = $jumlah_baru - $transaksi['jumlah'];
            
            // Update transaction
            $query_update = "UPDATE transaksi SET jumlah = :jumlah, saldo_sesudah = saldo_sesudah + :selisih, 
                            keterangan = CONCAT(keterangan, ' | Koreksi: ', :keterangan_koreksi)
                            WHERE id = :id";
            $stmt_update = $db->prepare($query_update);
            $stmt_update->bindParam(':jumlah', $jumlah_baru);
            $stmt_update->bindParam(':selisih', $selisih);
            $stmt_update->bindParam(':keterangan_koreksi', $keterangan_koreksi);
            $stmt_update->bindParam(':id', $transaksi_id);
            $stmt_update->execute();
            
            // Update simpanan
            $query_simpanan = "UPDATE simpanan SET saldo = saldo + :selisih, updated_at = NOW()
                              WHERE anggota_id = :anggota_id AND jenis_simpanan_id = :jenis_simpanan_id";
            $stmt_simpanan = $db->prepare($query_simpanan);
            $stmt_simpanan->bindParam(':selisih', $selisih);
            $stmt_simpanan->bindParam(':anggota_id', $transaksi['anggota_id']);
            $stmt_simpanan->bindParam(':jenis_simpanan_id', $transaksi['jenis_simpanan_id']);
            $stmt_simpanan->execute();
            
            // Update kas
            $jenis_kas = $transaksi['jenis_transaksi'] == 'setoran' ? 'masuk' : 'keluar';
            $query_kas = "UPDATE kas SET jumlah = jumlah + :selisih, saldo_sesudah = saldo_sesudah + :selisih
                         WHERE transaksi_id = :transaksi_id";
            $stmt_kas = $db->prepare($query_kas);
            $stmt_kas->bindParam(':selisih', $selisih);
            $stmt_kas->bindParam(':transaksi_id', $transaksi_id);
            $stmt_kas->execute();
            
            // Log koreksi
            $query_log = "INSERT INTO log_koreksi (transaksi_id, jumlah_lama, jumlah_baru, selisih, 
                         keterangan, user_id) 
                         VALUES (:transaksi_id, :jumlah_lama, :jumlah_baru, :selisih, 
                         :keterangan, :user_id)";
            $stmt_log = $db->prepare($query_log);
            $stmt_log->bindParam(':transaksi_id', $transaksi_id);
            $stmt_log->bindParam(':jumlah_lama', $transaksi['jumlah']);
            $stmt_log->bindParam(':jumlah_baru', $jumlah_baru);
            $stmt_log->bindParam(':selisih', $selisih);
            $stmt_log->bindParam(':keterangan', $keterangan_koreksi);
            $stmt_log->bindParam(':user_id', $_SESSION['user_id']);
            $stmt_log->execute();
            
            $db->commit();
            $_SESSION['success'] = "Koreksi transaksi berhasil dilakukan";
        } catch (Exception $e) {
            $db->rollBack();
            $_SESSION['error'] = "Gagal melakukan koreksi: " . $e->getMessage();
        }
    }
}

// Get transactions for correction (search + pagination)
$ui = ui_query_params([]);
$q = $ui['q'];
$from = " FROM transaksi t
          JOIN anggota a ON t.anggota_id = a.id
          JOIN jenis_simpanan j ON t.jenis_simpanan_id = j.id
          JOIN users u ON t.user_id = u.id
          WHERE j.kode_jenis <> 'SHU'";
$params = [];
if ($q !== '') {
    $from .= ' AND (a.nama_lengkap LIKE :q1 OR a.kode_anggota LIKE :q2 OR t.kode_transaksi LIKE :q3 OR t.keterangan LIKE :q4)';
    $params[':q1'] = $params[':q2'] = $params[':q3'] = $params[':q4'] = '%' . $q . '%';
}
$countStmt = $db->prepare('SELECT COUNT(*)' . $from);
foreach ($params as $k => $v) {
    $countStmt->bindValue($k, $v);
}
$countStmt->execute();
$pager = ui_paginate((int)$countStmt->fetchColumn(), $ui['page'], $ui['per_page']);
$query_transaksi = "SELECT t.*, a.nama_lengkap, j.nama_jenis, u.nama_lengkap as teller
                   $from
                   ORDER BY t.tanggal_transaksi DESC
                   LIMIT {$pager['per_page']} OFFSET {$pager['offset']}";
$stmt_transaksi = $db->prepare($query_transaksi);
foreach ($params as $k => $v) {
    $stmt_transaksi->bindValue($k, $v);
}
$stmt_transaksi->execute();
$transaksi_list = $stmt_transaksi->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Koreksi Transaksi - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <?php $uiAssetPrefix = '../'; include __DIR__ . '/../includes/layout/assets.php'; ?>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="container-fluid">
        <div class="row">
            <?php include '../includes/sidebar.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <?php ui_page_header([
                    'title' => 'Koreksi Transaksi',
                    'icon' => 'ti ti-edit',
                    'subtitle' => 'Perbaiki transaksi yang mempengaruhi saldo dan kas',
                ]); ?>
                <?php ui_flash(); ?>

                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <strong>Perhatian:</strong> Koreksi transaksi akan mempengaruhi saldo simpanan dan kas. 
                    Pastikan data yang dikoreksi sudah benar.
                </div>

                <!-- Daftar Transaksi -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-list me-2"></i>Daftar Transaksi Terbaru
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php
                        ui_table_toolbar([
                            'action' => 'koreksi.php',
                            'q' => $q,
                            'placeholder' => 'Cari kode, nama, keterangan…',
                            'per_page' => $pager['per_page'],
                            'reset_href' => 'koreksi.php',
                        ]);
                        ?>
                        <div class="table-responsive ui-table-scroll">
                            <table class="table table-striped align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Kode</th>
                                        <th>Tanggal</th>
                                        <th>Anggota</th>
                                        <th>Jenis</th>
                                        <th>Tipe</th>
                                        <th>Jumlah</th>
                                        <th>Keterangan</th>
                                        <th class="text-end">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!$transaksi_list): ?>
                                    <tr><td colspan="8" class="text-center text-muted py-4">Tidak ada data</td></tr>
                                    <?php else: foreach ($transaksi_list as $transaksi): ?>
                                    <tr>
                                        <td>
                                            <small class="fw-bold"><?php echo htmlspecialchars($transaksi['kode_transaksi']); ?></small>
                                        </td>
                                        <td>
                                            <small><?php echo formatTanggalUi($transaksi['tanggal_transaksi']); ?></small>
                                            <br>
                                            <small class="text-muted"><?php echo date('H:i', strtotime($transaksi['tanggal_transaksi'])); ?></small>
                                        </td>
                                        <td><?php echo ui_cell_ellipsis($transaksi['nama_lengkap'], 28); ?></td>
                                        <td><?php echo ui_cell_ellipsis($transaksi['nama_jenis'], 20); ?></td>
                                        <td>
                                            <span class="badge bg-<?php echo $transaksi['jenis_transaksi'] == 'setoran' ? 'success' : 'warning'; ?><?php echo $transaksi['jenis_transaksi'] == 'setoran' ? '' : ' text-dark'; ?>">
                                                <?php echo ucfirst($transaksi['jenis_transaksi']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo formatRupiah($transaksi['jumlah']); ?></td>
                                        <td>
                                            <small><?php echo ui_cell_ellipsis($transaksi['keterangan'], 32); ?></small>
                                            <br>
                                            <small class="text-muted">Oleh: <?php echo htmlspecialchars($transaksi['teller']); ?></small>
                                        </td>
                                        <td class="table-actions text-end">
                                            <button class="btn btn-sm btn-warning" data-bs-toggle="modal" 
                                                    data-bs-target="#koreksiModal" 
                                                    data-id="<?php echo (int)$transaksi['id']; ?>"
                                                    data-kode="<?php echo htmlspecialchars($transaksi['kode_transaksi'], ENT_QUOTES, 'UTF-8'); ?>"
                                                    data-jumlah="<?php echo htmlspecialchars((string)$transaksi['jumlah'], ENT_QUOTES, 'UTF-8'); ?>"
                                                    data-jenis="<?php echo htmlspecialchars($transaksi['jenis_transaksi'], ENT_QUOTES, 'UTF-8'); ?>"
                                                    data-anggota="<?php echo htmlspecialchars($transaksi['nama_lengkap'], ENT_QUOTES, 'UTF-8'); ?>">
                                                <i class="fas fa-edit"></i> Koreksi
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php ui_pagination($pager, 'koreksi.php'); ?>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Koreksi Modal -->
    <div class="modal fade" id="koreksiModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST"><?php echo layananToken(); ?>
                    <div class="modal-header">
                        <h5 class="modal-title">Koreksi Transaksi</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="transaksi_id" id="koreksi_id">
                        
                        <div class="mb-3">
                            <label class="form-label">Kode Transaksi</label>
                            <input type="text" id="koreksi_kode" class="form-control" readonly>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Anggota</label>
                            <input type="text" id="koreksi_anggota" class="form-control" readonly>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Jenis Transaksi</label>
                            <input type="text" id="koreksi_jenis" class="form-control" readonly>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Jumlah Lama</label>
                                    <input type="text" id="koreksi_jumlah_lama" class="form-control" readonly>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Jumlah Baru *</label>
                                    <input type="number" name="jumlah_baru" class="form-control" 
                                           required step="1000" min="0" id="koreksi_jumlah_baru">
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Selisih</label>
                            <input type="text" id="koreksi_selisih" class="form-control" readonly>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Keterangan Koreksi *</label>
                            <textarea name="keterangan_koreksi" class="form-control" rows="3" required
                                      placeholder="Alasan koreksi transaksi"></textarea>
                        </div>
                        
                        <div class="alert alert-info">
                            <small>
                                <i class="fas fa-info-circle me-1"></i>
                                Koreksi akan mempengaruhi saldo simpanan dan kas secara otomatis.
                            </small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" name="koreksi" class="btn btn-warning">Proses Koreksi</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        var koreksiModal = document.getElementById('koreksiModal');
        koreksiModal.addEventListener('show.bs.modal', function (event) {
            var button = event.relatedTarget;
            document.getElementById('koreksi_id').value = button.getAttribute('data-id');
            document.getElementById('koreksi_kode').value = button.getAttribute('data-kode');
            document.getElementById('koreksi_anggota').value = button.getAttribute('data-anggota');
            document.getElementById('koreksi_jenis').value = button.getAttribute('data-jenis');
            
            const jumlahLama = parseFloat(button.getAttribute('data-jumlah'));
            document.getElementById('koreksi_jumlah_lama').value = 'Rp ' + jumlahLama.toLocaleString('id-ID');
            document.getElementById('koreksi_jumlah_baru').value = jumlahLama;
            
            // Calculate selisih
            updateSelisih();
        });

        // Update selisih when jumlah baru changes
        document.getElementById('koreksi_jumlah_baru').addEventListener('input', updateSelisih);

        function updateSelisih() {
            const jumlahLama = parseFloat(document.getElementById('koreksi_jumlah_lama').value.replace(/[^\d]/g, '')) || 0;
            const jumlahBaru = parseFloat(document.getElementById('koreksi_jumlah_baru').value) || 0;
            const selisih = jumlahBaru - jumlahLama;
            
            const selisihElement = document.getElementById('koreksi_selisih');
            selisihElement.value = 'Rp ' + Math.abs(selisih).toLocaleString('id-ID');
            
            if (selisih > 0) {
                selisihElement.className = 'form-control text-success';
            } else if (selisih < 0) {
                selisihElement.className = 'form-control text-danger';
            } else {
                selisihElement.className = 'form-control';
            }
        }
    </script>
</body>
</html>