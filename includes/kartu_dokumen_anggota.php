<?php
/**
 * Kartu "Dokumen Anggota" untuk halaman detail anggota. Butuh $member (baris anggota).
 * Thumbnail bisa diklik untuk diperbesar (includes/modal_dokumen.php).
 */
?>
<div class="card mb-3 ui-member-docs">
    <div class="card-header ui-dash-sec">
        <span class="ui-dash-sec-title"><i class="ti ti-id" aria-hidden="true"></i>Dokumen Anggota</span>
    </div>
    <div class="card-body">
        <div class="row g-2 text-center">
            <?php foreach (daftarDokumenAnggota() as $field => $label): $url = urlDokumenAnggota(isset($member[$field]) ? $member[$field] : ''); ?>
            <div class="col-4">
                <div class="ui-member-doc-slot">
                    <?php if ($url): ?>
                        <img src="<?php echo htmlspecialchars($url); ?>" class="img-thumbnail thumb-dokumen" alt="<?php echo htmlspecialchars($label); ?>"
                             onclick="lihatDokumen(this.src, '<?php echo htmlspecialchars($label, ENT_QUOTES); ?>')">
                    <?php else: ?>
                        <div class="ui-member-doc-empty">Belum ada</div>
                    <?php endif; ?>
                    <span class="ui-member-doc-label"><?php echo htmlspecialchars($label); ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
