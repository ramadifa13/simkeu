<form method="post" class="card mt-3 ui-ly-settings" id="serviceSettings">
    <?= layananToken() ?><input type="hidden" name="aksi" value="simpan_pengaturan">
    <div class="card-header ui-dash-sec">
        <div>
            <span class="ui-dash-sec-title"><i class="ti ti-adjustments" aria-hidden="true"></i>Kebijakan pinjaman &amp; loyalitas</span>
            <p class="text-muted small mb-0 mt-1">Tarif, pemutihan, dan template pesan — simpan sekali di akhir.</p>
        </div>
        <span class="badge bg-primary">Khusus Admin</span>
    </div>
    <div class="card-body">
        <section class="ui-ly-set-block">
            <div class="ui-ly-set-head">
                <span class="ui-ly-set-icon"><i class="ti ti-percentage" aria-hidden="true"></i></span>
                <div>
                    <h3 class="ui-ly-set-title">Tarif &amp; kebijakan</h3>
                    <p class="ui-ly-set-help mb-0">Nilai default saat Admin memproses pelunasan / denda jaminan.</p>
                </div>
            </div>
            <div class="ui-ly-settings-grid">
                <div>
                    <label for="diskon_pelunasan" class="form-label">Diskon sisa bunga pelunasan dipercepat</label>
                    <div class="input-group"><input id="diskon_pelunasan" name="diskon" type="number" class="form-control" min="0" max="100" step="0.01" value="<?= layananEscape($settings['diskon_pelunasan']) ?>" required><span class="input-group-text">%</span></div>
                    <p class="form-text">Usulan diskon saat Admin meninjau permohonan pelunasan.</p>
                </div>
                <div>
                    <label for="tarif_jaminan" class="form-label">Denda jaminan per hari</label>
                    <div class="input-group"><span class="input-group-text">Rp</span><input id="tarif_jaminan" name="tarif_jaminan" type="number" class="form-control" min="0" step="0.01" value="<?= layananEscape($settings['denda_jaminan_per_juta']) ?>" required></div>
                    <p class="form-text">Per Rp 1 juta pinjaman. Denda tanpa jaminan di <a href="pengaturan_pinjaman.php">Pengaturan Pinjaman</a>.</p>
                </div>
                <div class="ui-ly-toggle-policy">
                    <label for="pemutihan" class="ui-ly-check"><input id="pemutihan" type="checkbox" name="pemutihan" value="1" <?= $settings['pemutihan']?'checked':'' ?>> Izinkan pemutihan denda</label>
                    <p class="form-text mb-0">Tetap butuh keputusan Admin dan tercatat di log aktivitas.</p>
                </div>
            </div>
        </section>

        <section class="ui-ly-set-block mt-3">
            <div class="ui-ly-set-head">
                <span class="ui-ly-set-icon is-doc"><i class="ti ti-template" aria-hidden="true"></i></span>
                <div>
                    <h3 class="ui-ly-set-title">Template pesan &amp; dokumen</h3>
                    <p class="ui-ly-set-help mb-0">Placeholder <code>{nama}</code>, <code>{hari}</code>, dll. diganti otomatis.</p>
                </div>
            </div>
            <div class="accordion ui-ly-accordion" id="layananTemplateAcc">
            <?php
            $templates = [
                'wa_denda' => ['Pesan WhatsApp tagihan', '{nama}, {hari}, {denda}', 'ti-brand-whatsapp', true],
                'wa_lunas' => ['Pesan WhatsApp lunas', '{nama}, {norek}', 'ti-brand-whatsapp', false],
                'surat_lunas' => ['Surat keterangan lunas', '{nama}, {norek}, {tanggal}', 'ti-file-text', false],
                'bast_jaminan' => ['BAST pengembalian jaminan', '{nama}, {norek}, {tanggal}', 'ti-package', false],
            ];
            $i = 0;
            foreach ($templates as $settingKey => $settingInfo):
                $i++;
                $collapseId = 'tpl_' . $settingKey;
                $open = $settingInfo[3];
            ?>
                <div class="accordion-item">
                    <h2 class="accordion-header" id="h_<?= $collapseId ?>">
                        <button class="accordion-button<?= $open ? '' : ' collapsed' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#<?= $collapseId ?>" aria-expanded="<?= $open ? 'true' : 'false' ?>" aria-controls="<?= $collapseId ?>">
                            <i class="ti <?= $settingInfo[2] ?> me-2" aria-hidden="true"></i><?= layananEscape($settingInfo[0]) ?>
                        </button>
                    </h2>
                    <div id="<?= $collapseId ?>" class="accordion-collapse collapse<?= $open ? ' show' : '' ?>" aria-labelledby="h_<?= $collapseId ?>" data-bs-parent="#layananTemplateAcc">
                        <div class="accordion-body">
                            <label class="form-label visually-hidden" for="template_<?= $settingKey ?>"><?= layananEscape($settingInfo[0]) ?></label>
                            <textarea id="template_<?= $settingKey ?>" name="<?= $settingKey ?>" class="form-control" rows="5" maxlength="<?= strpos($settingKey,'wa_')===0?2000:4000 ?>" required><?= layananEscape($settings[$settingKey]) ?></textarea>
                            <div class="form-text">Tersedia: <?= layananEscape($settingInfo[1]) ?></div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        </section>
    </div>
    <div class="card-footer ui-ly-settings-footer">
        <span class="text-muted small">Perubahan disimpan bersama dan dicatat pada audit.</span>
        <button class="btn btn-primary"><i class="ti ti-check me-1" aria-hidden="true"></i> Simpan kebijakan</button>
    </div>
</form>
