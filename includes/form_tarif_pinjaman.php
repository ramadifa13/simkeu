<?php
/**
 * Potongan form: bunga flat per tenor + frekuensi angsuran yang diizinkan.
 * Dipakai di modal tambah & edit admin/jenis_pinjaman.php. Butuh $tarif_prefix.
 */
?>
<div class="mb-3">
    <div class="form-check">
        <input class="form-check-input" type="checkbox" name="memerlukan_jaminan" value="1" id="<?php echo $tarif_prefix; ?>_jaminan">
        <label class="form-check-label" for="<?php echo $tarif_prefix; ?>_jaminan"><strong>Pinjaman gadai / wajib jaminan barang</strong></label>
    </div>
    <small class="text-muted">Gadai menggunakan bunga dasar per tenor ditambah biaya mingguan sesuai nominal.</small>
</div>
<div class="mb-3" id="<?php echo $tarif_prefix; ?>_tarif_section">
    <label class="form-label">Bunga (flat per tenor) *</label>
    <table class="table table-sm align-middle mb-1">
        <thead>
            <tr><th style="width:45%">Tenor</th><th style="width:45%">Bunga</th><th></th></tr>
        </thead>
        <tbody id="<?php echo $tarif_prefix; ?>_tarif_rows"></tbody>
    </table>
    <button type="button" class="btn btn-sm btn-outline-primary" onclick="tambahBarisTarif('<?php echo $tarif_prefix; ?>')">
        <i class="fas fa-plus me-1"></i> Tambah Tenor
    </button>
    <div><small class="text-muted">
        Bunga untuk keseluruhan tenor: total kembali = pinjaman + (pinjaman &times; bunga%).
        Contoh: 1 bulan = 15%, 3 bulan = 35%. Maksimal <?php echo BUNGA_MAKS; ?>%.
    </small></div>
</div>
<div class="mb-3" id="<?php echo $tarif_prefix; ?>_frekuensi_section">
    <label class="form-label d-block">Frekuensi Angsuran yang Diizinkan *</label>
    <small class="text-muted d-block mb-1">Pilih frekuensi yang dapat digunakan Teller saat membuat pengajuan.</small>
    <div class="form-check form-check-inline">
        <input class="form-check-input" type="checkbox" name="frekuensi[]" value="bulanan" id="<?php echo $tarif_prefix; ?>_frek_bulanan">
        <label class="form-check-label" for="<?php echo $tarif_prefix; ?>_frek_bulanan">Bulanan</label>
    </div>
    <div class="form-check form-check-inline">
        <input class="form-check-input" type="checkbox" name="frekuensi[]" value="mingguan" id="<?php echo $tarif_prefix; ?>_frek_mingguan">
        <label class="form-check-label" for="<?php echo $tarif_prefix; ?>_frek_mingguan">Mingguan (cicilan = tenor bulan &times; 4)</label>
    </div>
    <div class="form-check form-check-inline">
        <input class="form-check-input" type="checkbox" name="frekuensi[]" value="dua_mingguan" id="<?php echo $tarif_prefix; ?>_frek_dua_mingguan">
        <label class="form-check-label" for="<?php echo $tarif_prefix; ?>_frek_dua_mingguan">2 minggu sekali (khusus tenor 6 bulan = 12 cicilan)</label>
    </div>
</div>
