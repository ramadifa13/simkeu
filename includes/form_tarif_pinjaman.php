<?php
/**
 * Potongan form: bunga flat per tenor + frekuensi angsuran yang diizinkan.
 * Dipakai di modal tambah & edit admin/jenis_pinjaman.php. Butuh $tarif_prefix.
 */
$p = preg_replace('/[^a-z]/', '', (string)($tarif_prefix ?? 'add')) ?: 'add';
?>
<section class="ui-jpin-section">
    <header class="ui-jpin-section-head">
        <i class="ti ti-shield-check" aria-hidden="true"></i>
        <div>
            <strong>Jenis produk</strong>
            <span>Gadai memakai skema bunga &amp; biaya khusus</span>
        </div>
    </header>
    <label class="ui-jpin-gadai" for="<?php echo htmlspecialchars($p); ?>_jaminan">
        <input class="ui-jpin-gadai-input" type="checkbox" name="memerlukan_jaminan" value="1" id="<?php echo htmlspecialchars($p); ?>_jaminan">
        <span class="ui-jpin-gadai-card">
            <span class="ui-jpin-gadai-icon" aria-hidden="true"><i class="ti ti-building-warehouse"></i></span>
            <span class="ui-jpin-gadai-copy">
                <strong>Pinjaman gadai / wajib jaminan barang</strong>
                <small>Bunga dasar 15% per tenor (1–6 bln) + biaya mingguan sesuai nominal</small>
            </span>
            <span class="ui-jpin-gadai-switch" aria-hidden="true"></span>
        </span>
    </label>
</section>

<section class="ui-jpin-section" id="<?php echo htmlspecialchars($p); ?>_tarif_section">
    <header class="ui-jpin-section-head">
        <i class="ti ti-percentage" aria-hidden="true"></i>
        <div>
            <strong>Bunga flat per tenor *</strong>
            <span>Total kembali = pinjaman + (pinjaman × bunga%). Maks <?php echo (int)BUNGA_MAKS; ?>%</span>
        </div>
    </header>
    <div class="ui-jpin-tarif-table-wrap">
        <table class="table table-sm align-middle mb-0 ui-jpin-tarif-table">
            <thead>
                <tr>
                    <th>Tenor</th>
                    <th>Bunga flat</th>
                    <th class="text-end"></th>
                </tr>
            </thead>
            <tbody id="<?php echo htmlspecialchars($p); ?>_tarif_rows"></tbody>
        </table>
    </div>
    <div class="ui-jpin-tarif-actions">
        <button type="button" class="btn btn-sm btn-outline-primary" onclick="tambahBarisTarif('<?php echo htmlspecialchars($p); ?>')">
            <i class="ti ti-plus me-1" aria-hidden="true"></i>Tambah tenor
        </button>
        <p class="form-text mb-0">Contoh: 1 bulan = 15%, 3 bulan = 35%</p>
    </div>
</section>

<section class="ui-jpin-section" id="<?php echo htmlspecialchars($p); ?>_frekuensi_section">
    <header class="ui-jpin-section-head">
        <i class="ti ti-calendar-repeat" aria-hidden="true"></i>
        <div>
            <strong>Frekuensi angsuran *</strong>
            <span>Pilih yang boleh dipakai Teller saat pengajuan</span>
        </div>
    </header>
    <div class="ui-jpin-frek" role="group" aria-label="Frekuensi angsuran">
        <label class="ui-jpin-frek-opt">
            <input type="checkbox" name="frekuensi[]" value="bulanan" id="<?php echo htmlspecialchars($p); ?>_frek_bulanan">
            <span>
                <i class="ti ti-calendar-month" aria-hidden="true"></i>
                <strong>Bulanan</strong>
                <small>1 cicilan / bulan</small>
            </span>
        </label>
        <label class="ui-jpin-frek-opt">
            <input type="checkbox" name="frekuensi[]" value="mingguan" id="<?php echo htmlspecialchars($p); ?>_frek_mingguan">
            <span>
                <i class="ti ti-calendar-week" aria-hidden="true"></i>
                <strong>Mingguan</strong>
                <small>Cicilan = tenor × 4</small>
            </span>
        </label>
        <label class="ui-jpin-frek-opt">
            <input type="checkbox" name="frekuensi[]" value="dua_mingguan" id="<?php echo htmlspecialchars($p); ?>_frek_dua_mingguan">
            <span>
                <i class="ti ti-calendar-stats" aria-hidden="true"></i>
                <strong>2 minggu sekali</strong>
                <small>Khusus tenor 6 bln · bunga 45%</small>
            </span>
        </label>
    </div>
</section>
