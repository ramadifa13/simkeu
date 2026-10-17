<?php
/**
 * Kartu "Dokumen Anggota" untuk halaman detail anggota. Butuh $member (baris anggota).
 * Thumbnail bisa diklik untuk diperbesar (includes/modal_dokumen.php).
 */
?>
<div class="card mb-4">
    <div class="card-header bg-white">
        <h6 class="mb-0"><i class="fas fa-id-card me-2 text-primary"></i>Dokumen Anggota</h6>
    </div>
    <div class="card-body">
        <div class="row g-2 text-center">
            <?php foreach (daftarDokumenAnggota() as $field => $label): $url = urlDokumenAnggota(isset($member[$field]) ? $member[$field] : ''); ?>
            <div class="col-4">
                <?php if ($url): ?>
                    <img src="<?php echo htmlspecialchars($url); ?>" class="img-thumbnail thumb-dokumen w-100" alt="<?php echo htmlspecialchars($label); ?>"
                         onclick="lihatDokumen(this.src, '<?php echo htmlspecialchars($label, ENT_QUOTES); ?>')">
                <?php else: ?>
                    <div class="border rounded d-flex align-items-center justify-content-center text-muted" style="height:110px">
                        <small>Belum ada</small>
                    </div>
                <?php endif; ?>
                <small class="d-block mt-1"><?php echo $label; ?></small>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
