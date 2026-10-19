<form method="post" class="card service-section service-settings" id="serviceSettings">
    <?= layananToken() ?><input type="hidden" name="aksi" value="simpan_pengaturan">
    <div class="card-header"><div><h2 class="h5 mb-1">Kebijakan pinjaman & loyalitas</h2><p class="text-muted mb-0 small">Periksa tarif dan template sebelum menyimpan perubahan.</p></div><span class="badge bg-primary">Khusus Admin</span></div>
    <div class="card-body">
        <div class="service-settings-grid">
            <div><label for="diskon_pelunasan" class="form-label">Diskon sisa bunga pelunasan dipercepat</label><div class="input-group"><input id="diskon_pelunasan" name="diskon" type="number" class="form-control" min="0" max="100" step="0.01" value="<?= layananEscape($settings['diskon_pelunasan']) ?>" required><span class="input-group-text">%</span></div><p class="form-text">Usulan diskon saat Admin meninjau permohonan pelunasan.</p></div>
            <div><label for="tarif_jaminan" class="form-label">Denda jaminan per hari</label><div class="input-group"><span class="input-group-text">Rp</span><input id="tarif_jaminan" name="tarif_jaminan" type="number" class="form-control" min="0" step="0.01" value="<?= layananEscape($settings['denda_jaminan_per_juta']) ?>" required></div><p class="form-text">Per Rp 1 juta pinjaman. Denda tanpa jaminan diatur di <a href="pengaturan_pinjaman.php">Pengaturan Pinjaman</a>.</p></div>
            <div class="service-toggle-policy"><label for="pemutihan"><input id="pemutihan" type="checkbox" name="pemutihan" value="1" <?= $settings['pemutihan']?'checked':'' ?>> Izinkan pemutihan denda</label><p class="form-text mb-0">Penghapusan atau diskon denda tetap membutuhkan keputusan Admin dan dicatat pada log aktivitas.</p></div>
        </div>
        <hr class="my-4"><h3 class="h6">Template pesan & dokumen</h3><p class="text-muted small">Placeholder di dalam kurung kurawal akan diganti dengan data nasabah saat digunakan.</p>
        <div class="service-settings-grid">
        <?php foreach(['wa_denda'=>['Pesan WhatsApp tagihan','{nama}, {hari}, {denda}'],'wa_lunas'=>['Pesan WhatsApp lunas','{nama}, {norek}'],'surat_lunas'=>['Surat keterangan lunas','{nama}, {norek}, {tanggal}'],'bast_jaminan'=>['BAST pengembalian jaminan','{nama}, {norek}, {tanggal}']] as $settingKey=>$settingInfo): ?>
            <div><label class="form-label" for="template_<?= $settingKey ?>"><?= layananEscape($settingInfo[0]) ?></label><textarea id="template_<?= $settingKey ?>" name="<?= $settingKey ?>" class="form-control" rows="5" maxlength="<?= strpos($settingKey,'wa_')===0?2000:4000 ?>" required><?= layananEscape($settings[$settingKey]) ?></textarea><div class="form-text">Tersedia: <?= layananEscape($settingInfo[1]) ?></div></div>
        <?php endforeach; ?>
        </div>
    </div>
    <div class="card-footer service-settings-footer"><span class="text-muted small">Perubahan disimpan bersama dan dicatat pada audit.</span><button class="btn btn-primary"><i class="fas fa-check me-1" aria-hidden="true"></i> Simpan kebijakan</button></div>
</form>
