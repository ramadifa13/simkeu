<?php
/**
 * Lengkapi foto pencairan untuk pinjaman migrasi / tanpa dokumen.
 * Dipakai Admin dan Teller dari halaman pencairan.
 */
require_once __DIR__ . '/upload_foto.php';

if (!function_exists('prosesLengkapiFotoPencairan')) {
    /**
     * @return string path relatif foto pencairan yang disimpan
     */
    function prosesLengkapiFotoPencairan(PDO $db, $pinjaman_id, $user_id) {
        $pinjaman_id = (int) $pinjaman_id;
        if ($pinjaman_id <= 0) {
            throw new Exception('ID pinjaman tidak valid.');
        }

        $stmt = $db->prepare(
            "SELECT p.id, p.pengajuan_id, p.foto_pencairan, p.kode_pinjaman, jp.memerlukan_jaminan, j.id AS jaminan_id
             FROM pinjaman p
             JOIN jenis_pinjaman jp ON jp.id = p.jenis_pinjaman_id
             LEFT JOIN jaminan_pinjaman j ON j.pinjaman_id = p.id
             WHERE p.id = ?"
        );
        $stmt->execute([$pinjaman_id]);
        $pinjaman = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$pinjaman) {
            throw new Exception('Pinjaman tidak ditemukan.');
        }

        $foto = validasiFotoUpload('foto_nasabah', 'Foto nasabah saat pencairan', 1024 * 1024, true);
        $adalah_gadai = !empty($pinjaman['memerlukan_jaminan']);
        // Foto jaminan opsional saat melengkapi data migrasi; jika diunggah harus tersimpan.
        $foto_barang = $adalah_gadai ? validasiFotoUpload('foto_barang_jaminan', 'Foto barang jaminan', 1024 * 1024, false) : null;
        $foto_nasabah_barang = $adalah_gadai ? validasiFotoUpload('foto_nasabah_barang', 'Foto nasabah dengan barang jaminan', 1024 * 1024, false) : null;

        $foto_path = simpanFotoUpload($foto, 'pencairan');
        $foto_barang_path = null;
        $foto_nasabah_barang_path = null;
        $fotoLama = (string) ($pinjaman['foto_pencairan'] ?? '');
        try {
            if ($foto_barang) {
                $foto_barang_path = simpanFotoUpload($foto_barang, 'jaminan');
            }
            if ($foto_nasabah_barang) {
                $foto_nasabah_barang_path = simpanFotoUpload($foto_nasabah_barang, 'jaminan');
            }

            $db->beginTransaction();
            $db->prepare("UPDATE pinjaman SET foto_pencairan = ? WHERE id = ?")->execute([$foto_path, $pinjaman_id]);

            if ($adalah_gadai && ($foto_barang_path || $foto_nasabah_barang_path)) {
                $jaminanId = (int) ($pinjaman['jaminan_id'] ?? 0);
                if ($jaminanId <= 0) {
                    $pengajuanId = (int) ($pinjaman['pengajuan_id'] ?? 0);
                    if ($pengajuanId <= 0) {
                        throw new Exception('Pengajuan pinjaman tidak ditemukan; tidak dapat menyimpan foto jaminan.');
                    }
                    $db->prepare(
                        "INSERT INTO jaminan_pinjaman
                         (pengajuan_id, pinjaman_id, jenis_barang, identitas_barang, deskripsi_barang, nilai_taksiran, foto_barang, foto_nasabah_barang, status_jaminan)
                         VALUES (?, ?, 'Dilengkapi migrasi', '-', 'Foto jaminan dilengkapi setelah migrasi', 0, ?, ?, 'dititipkan')"
                    )->execute([$pengajuanId, $pinjaman_id, $foto_barang_path, $foto_nasabah_barang_path]);
                } else {
                    $fields = [];
                    $vals = [];
                    if ($foto_barang_path) {
                        $fields[] = 'foto_barang = ?';
                        $vals[] = $foto_barang_path;
                    }
                    if ($foto_nasabah_barang_path) {
                        $fields[] = 'foto_nasabah_barang = ?';
                        $vals[] = $foto_nasabah_barang_path;
                    }
                    $vals[] = $jaminanId;
                    $db->prepare('UPDATE jaminan_pinjaman SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($vals);
                }
            }

            if (function_exists('layananAudit')) {
                layananAudit($db, $pinjaman_id, 'lengkapi_foto_pencairan', 'Foto pencairan dilengkapi oleh user #' . (int) $user_id);
            }
            $db->commit();

            if ($fotoLama !== '' && $fotoLama !== $foto_path) {
                hapusFotoUpload($fotoLama);
            }
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            hapusFotoUpload($foto_path);
            hapusFotoUpload($foto_barang_path);
            hapusFotoUpload($foto_nasabah_barang_path);
            throw $e;
        }

        return $foto_path;
    }
}

if (!function_exists('renderModalLengkapiFotoPencairan')) {
    function renderModalLengkapiFotoPencairan() {
        ?>
        <div class="modal fade" id="lengkapiFotoModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="post" enctype="multipart/form-data" id="formLengkapiFoto">
                        <?= function_exists('layananToken') ? layananToken() : '' ?>
                        <input type="hidden" name="lengkapi_foto_pencairan" value="1">
                        <input type="hidden" name="pinjaman_id" id="lengkapi_pinjaman_id" value="">
                        <div class="modal-header bg-primary text-white">
                            <h5 class="modal-title"><i class="fas fa-camera me-2"></i>Lengkapi foto pencairan</h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Tutup"></button>
                        </div>
                        <div class="modal-body">
                            <p class="mb-2">Pinjaman: <strong id="lengkapi_kode_pinjaman">-</strong><br>
                                Nasabah: <strong id="lengkapi_nama_nasabah">-</strong></p>
                            <p class="small text-muted">Untuk data hasil migrasi yang belum punya foto proses pencairan. Unggah foto nasabah saat menerima uang.</p>
                            <div class="mb-3">
                                <label class="form-label" for="lengkapi_foto_nasabah">Foto nasabah saat pencairan <span class="text-danger">*</span></label>
                                <input type="file" name="foto_nasabah" id="lengkapi_foto_nasabah" class="form-control" accept="image/jpeg,image/png,image/webp" required>
                            </div>
                            <div id="lengkapi_blok_jaminan" class="d-none">
                                <div class="mb-3">
                                    <label class="form-label" for="lengkapi_foto_barang">Foto barang jaminan</label>
                                    <input type="file" name="foto_barang_jaminan" id="lengkapi_foto_barang" class="form-control" accept="image/jpeg,image/png,image/webp">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label" for="lengkapi_foto_nasabah_barang">Foto nasabah dengan barang</label>
                                    <input type="file" name="foto_nasabah_barang" id="lengkapi_foto_nasabah_barang" class="form-control" accept="image/jpeg,image/png,image/webp">
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                            <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Simpan foto</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <script>
        document.querySelectorAll('[data-lengkapi-foto]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                document.getElementById('lengkapi_pinjaman_id').value = btn.getAttribute('data-id') || '';
                document.getElementById('lengkapi_kode_pinjaman').textContent = btn.getAttribute('data-kode') || '-';
                document.getElementById('lengkapi_nama_nasabah').textContent = btn.getAttribute('data-nama') || '-';
                var gadai = btn.getAttribute('data-gadai') === '1';
                document.getElementById('lengkapi_blok_jaminan').classList.toggle('d-none', !gadai);
            });
        });
        </script>
        <?php
    }
}
