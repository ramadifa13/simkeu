<x-layouts.portal :role="$role" title="Perjanjian & Layanan">
    <div class="mx-auto max-w-5xl">
        <x-page-header
            class="ui-page-header-rich"
            icon="document-text"
            eyebrow="Pengaturan"
            title="Perjanjian & layanan."
            description="Template perjanjian, tanda tangan Admin, serta parameter diskon, denda, dan bonus. Berlaku untuk dokumen baru."
            :back-href="route('portal.settings.cooperative.edit', ['role' => $role])"
            back-label="Profil koperasi"
        />

        <form method="POST" action="{{ route('portal.settings.agreement.update', ['role' => $role]) }}" enctype="multipart/form-data" class="mt-8 space-y-5">
            @csrf
            @method('PUT')
            <x-validation-summary />
            <x-required-hint />

            <x-form-section title="Pihak pertama perjanjian" description="Data ini menjadi snapshot pada perjanjian pinjaman baru.">
                <div class="grid gap-4 md:grid-cols-2">
                    <flux:field>
                        <flux:label>Nama pihak pertama</flux:label>
                        <flux:input name="nama_pihak_pertama" :value="old('nama_pihak_pertama', $agreement?->nama_pihak_pertama)" />
                        <flux:error name="nama_pihak_pertama" />
                    </flux:field>
                    <flux:field>
                        <flux:label>NIK pihak pertama</flux:label>
                        <flux:input name="nik_pihak_pertama" :value="old('nik_pihak_pertama', $agreement?->nik_pihak_pertama)" inputmode="numeric" />
                        <flux:error name="nik_pihak_pertama" />
                    </flux:field>
                    <flux:field>
                        <flux:label>Telepon</flux:label>
                        <flux:input name="telepon_pihak_pertama" :value="old('telepon_pihak_pertama', $agreement?->telepon_pihak_pertama)" inputmode="tel" />
                        <flux:error name="telepon_pihak_pertama" />
                    </flux:field>
                    <flux:field>
                        <flux:label>Jabatan penandatangan <x-required-mark /></flux:label>
                        <flux:input name="jabatan_admin" :value="old('jabatan_admin', $agreement?->jabatan_admin ?? 'Pengurus Koperasi')" required />
                        <flux:error name="jabatan_admin" />
                    </flux:field>
                    <flux:field class="md:col-span-2">
                        <flux:label>Alamat pihak pertama</flux:label>
                        <flux:textarea name="alamat_pihak_pertama" rows="3">{{ old('alamat_pihak_pertama', $agreement?->alamat_pihak_pertama) }}</flux:textarea>
                        <flux:error name="alamat_pihak_pertama" />
                    </flux:field>
                </div>
            </x-form-section>

            <x-form-section title="Template & tanda tangan" description="Tanda tangan disimpan di storage privat. Dokumen yang sudah ditandatangani tidak diubah.">
                <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_20rem]">
                    <div class="space-y-4">
                        <flux:field>
                            <flux:label>Template default <x-required-mark /></flux:label>
                            <flux:select name="template_default" required>
                                <option value="lengkap" @selected(old('template_default', $agreement?->template_default) === 'lengkap')>Lengkap</option>
                                <option value="ringkas" @selected(old('template_default', $agreement?->template_default) === 'ringkas')>Ringkas</option>
                            </flux:select>
                            <flux:error name="template_default" />
                        </flux:field>
                        <flux:field>
                            <flux:label>Isi template ringkas <x-required-mark /></flux:label>
                            <flux:textarea name="isi_ringkas" rows="7" required>{{ old('isi_ringkas', $agreement?->isi_ringkas) }}</flux:textarea>
                            <flux:error name="isi_ringkas" />
                        </flux:field>
                        <flux:field>
                            <flux:label>Isi template lengkap <x-required-mark /></flux:label>
                            <flux:textarea name="isi_lengkap" rows="10" required>{{ old('isi_lengkap', $agreement?->isi_lengkap) }}</flux:textarea>
                            <flux:error name="isi_lengkap" />
                        </flux:field>
                    </div>
                    <aside>
                        <x-signature-field
                            label="Tanda tangan Admin (opsional)"
                            name="ttd_admin"
                            :current-url="filled($agreement?->ttd_admin) ? route('portal.settings.agreement.signature', ['role' => $role, 'v' => basename((string) $agreement->ttd_admin)]) : null"
                        />
                        @if (filled($agreement?->ttd_admin))
                            <p class="mt-3 rounded-lg bg-emerald-50 px-3 py-2 text-xs font-medium text-emerald-800">Tanda tangan aktif tersimpan privat. Gambar baru akan mengganti dan menghapus yang lama.</p>
                        @endif
                    </aside>
                </div>
            </x-form-section>

            <x-form-section title="Parameter layanan" description="Konfigurasi master. Eksekusi diskon, denda, dan bonus tetap melalui workflow ber-audit.">
                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <flux:field>
                        <flux:label>Diskon pelunasan (%) <x-required-mark /></flux:label>
                        <flux:input type="number" min="0" max="100" step=".01" name="diskon_pelunasan" :value="old('diskon_pelunasan', $serviceSettings?->diskon_pelunasan ?? 10)" required />
                        <flux:error name="diskon_pelunasan" />
                    </flux:field>
                    <flux:field>
                        <flux:label>Denda jaminan per juta <x-required-mark /></flux:label>
                        <flux:input type="number" min="0" name="denda_jaminan_per_juta" :value="old('denda_jaminan_per_juta', $serviceSettings?->denda_jaminan_per_juta ?? 5000)" required />
                        <flux:error name="denda_jaminan_per_juta" />
                    </flux:field>
                    <flux:field>
                        <flux:label>Bonus (%) <x-required-mark /></flux:label>
                        <flux:input type="number" min="0" max="100" step=".01" name="bonus_persen" :value="old('bonus_persen', $serviceSettings?->bonus_persen ?? 0)" required />
                        <flux:error name="bonus_persen" />
                    </flux:field>
                    <label class="flex items-end gap-3 pb-2 text-sm font-medium text-slate-700">
                        <input type="hidden" name="pemutihan" value="0">
                        <input type="checkbox" name="pemutihan" value="1" @checked(old('pemutihan', $serviceSettings?->pemutihan)) class="rounded border-slate-300 text-teal-700 focus:ring-teal-600">
                        Pemutihan denda diizinkan
                    </label>
                </div>
            </x-form-section>

            <x-form-section title="Template pesan & dokumen" description="Placeholder seperti {nama}, {hari}, {norek}, {tanggal}, {denda} diganti otomatis saat dipakai.">
                <div class="grid gap-4 md:grid-cols-2">
                    <flux:field>
                        <flux:label>Pesan WhatsApp tagihan <x-required-mark /></flux:label>
                        <flux:textarea name="wa_denda" rows="5" required maxlength="2000">{{ old('wa_denda', $serviceSettings?->wa_denda) }}</flux:textarea>
                        <flux:description>Tersedia: {nama}, {hari}, {denda}</flux:description>
                        <flux:error name="wa_denda" />
                    </flux:field>
                    <flux:field>
                        <flux:label>Pesan WhatsApp lunas <x-required-mark /></flux:label>
                        <flux:textarea name="wa_lunas" rows="5" required maxlength="2000">{{ old('wa_lunas', $serviceSettings?->wa_lunas) }}</flux:textarea>
                        <flux:description>Tersedia: {nama}, {norek}</flux:description>
                        <flux:error name="wa_lunas" />
                    </flux:field>
                    <flux:field>
                        <flux:label>Surat keterangan lunas</flux:label>
                        <flux:textarea name="surat_lunas" rows="5" maxlength="4000">{{ old('surat_lunas', $serviceSettings?->surat_lunas) }}</flux:textarea>
                        <flux:description>Tersedia: {nama}, {norek}, {tanggal}</flux:description>
                        <flux:error name="surat_lunas" />
                    </flux:field>
                    <flux:field>
                        <flux:label>BAST pengembalian jaminan</flux:label>
                        <flux:textarea name="bast_jaminan" rows="5" maxlength="4000">{{ old('bast_jaminan', $serviceSettings?->bast_jaminan) }}</flux:textarea>
                        <flux:description>Tersedia: {nama}, {norek}, {tanggal}</flux:description>
                        <flux:error name="bast_jaminan" />
                    </flux:field>
                </div>
            </x-form-section>

            <x-form-actions note="Perjanjian yang sudah ditandatangani tetap immutable pada workflow pinjaman." :cancel-href="route('portal.settings.cooperative.edit', ['role' => $role])" submit-label="Simpan pengaturan" submit-icon="check" />
        </form>
    </div>
</x-layouts.portal>
