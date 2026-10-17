<?php
/**
 * Potongan form: upload dokumen anggota (KTP, KK, KTP suami/istri).
 * Variabel: $dok_prefix (id unik per form), $dok_anggota (baris anggota saat edit, opsional).
 * Form induk wajib memakai enctype="multipart/form-data".
 * Pratinjau dokumen lama diisi server (bila $dok_anggota ada) atau lewat JS isiPreviewDokumen().
 */
$dok_anggota = isset($dok_anggota) ? $dok_anggota : null;
?>
<div class="row dokumen-anggota" data-prefix="<?php echo $dok_prefix; ?>">
    <div class="col-12">
        <label class="form-label fw-semibold mb-1">Dokumen Anggota</label>
        <div><small class="text-muted">Format JPG, JPEG, PNG, atau WEBP. Maksimal 10 MB per file.</small></div>
    </div>
    <?php foreach (daftarDokumenAnggota() as $field => $label):
        $wajib = dokumenWajib($field);
        $lama = $dok_anggota && !empty($dok_anggota[$field]) ? urlDokumenAnggota($dok_anggota[$field]) : '';
    ?>
    <div class="col-md-4 mb-3">
        <label class="form-label small label-dokumen" data-field="<?php echo $field; ?>" data-label="<?php echo htmlspecialchars($label); ?>"><?php echo $label; ?><?php echo ($wajib && $dok_prefix !== 'edit') ? ' *' : ''; ?></label>
        <input type="file" name="<?php echo $field; ?>" class="form-control form-control-sm input-dokumen"
               data-field="<?php echo $field; ?>"
               accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
               data-label="<?php echo htmlspecialchars($label); ?>"
               data-wajib="<?php echo $wajib ? '1' : '0'; ?>"
               <?php echo ($wajib && $dok_prefix !== 'edit') ? 'required' : ''; ?>>
        <div class="preview-dokumen mt-2" id="<?php echo $dok_prefix . '_preview_' . $field; ?>">
            <?php if ($lama): ?>
                <img src="<?php echo htmlspecialchars($lama); ?>" class="img-thumbnail thumb-dokumen" alt="<?php echo htmlspecialchars($label); ?>"
                     onclick="lihatDokumen(this.src, '<?php echo htmlspecialchars($label, ENT_QUOTES); ?>')">
                <div><small class="text-muted">File saat ini &mdash; pilih file baru untuk mengganti</small></div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
