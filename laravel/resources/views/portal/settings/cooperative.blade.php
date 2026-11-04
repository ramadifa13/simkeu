<x-layouts.portal :role="$role" title="Pengaturan Koperasi">
    <div class="mx-auto max-w-5xl">
        <x-page-header
            class="ui-page-header-rich"
            icon="building-office-2"
            eyebrow="Pengaturan"
            title="Profil koperasi."
            description="Identitas, kontak, rekening bank, dan logo privat yang dipakai di seluruh aplikasi."
        />

        <form method="POST" action="{{ route('portal.settings.cooperative.update', ['role' => $role]) }}" enctype="multipart/form-data" class="mt-8 space-y-5">
            @csrf
            @method('PUT')
            <x-validation-summary />
            <x-required-hint />

            <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_20rem]">
                <x-form-section title="Identitas koperasi" description="Informasi ini dipakai pada halaman publik dan dokumen operasional.">
                    <div class="grid gap-4 md:grid-cols-2">
                        <flux:field class="md:col-span-2">
                            <flux:label>Nama koperasi <x-required-mark /></flux:label>
                            <flux:input name="nama" :value="old('nama', $cooperative?->nama)" required />
                            <flux:error name="nama" />
                        </flux:field>
                        <flux:field>
                            <flux:label>Telepon</flux:label>
                            <flux:input name="telepon" :value="old('telepon', $cooperative?->telepon)" inputmode="tel" />
                            <flux:error name="telepon" />
                        </flux:field>
                        <flux:field>
                            <flux:label>Email</flux:label>
                            <flux:input name="email" type="email" :value="old('email', $cooperative?->email)" />
                            <flux:error name="email" />
                        </flux:field>
                        <flux:field class="md:col-span-2">
                            <flux:label>Alamat</flux:label>
                            <flux:textarea name="alamat" rows="3">{{ old('alamat', $cooperative?->alamat) }}</flux:textarea>
                            <flux:error name="alamat" />
                        </flux:field>
                        <flux:field class="md:col-span-2">
                            <flux:label>Rekening bank</flux:label>
                            <flux:input name="rekening_bank" :value="old('rekening_bank', $cooperative?->rekening_bank)" placeholder="Contoh: BRI 1234567890 a.n. Koperasi" />
                            <flux:error name="rekening_bank" />
                        </flux:field>
                    </div>
                </x-form-section>

                <aside class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h2 class="text-base font-semibold text-slate-950">Logo koperasi</h2>
                    <p class="mt-1 text-sm text-slate-500">JPG, PNG, atau WebP. Maksimum 5 MB; disimpan privat dan teroptimasi.</p>
                    @if (filled($cooperative?->logo))
                        <figure class="mt-5 overflow-hidden rounded-2xl border border-slate-200 bg-slate-50 p-4">
                            <img
                                src="{{ route('brand.logo', ['v' => basename((string) $cooperative->logo)]) }}"
                                alt="Logo koperasi aktif"
                                class="mx-auto max-h-40 w-auto object-contain"
                            >
                            <figcaption class="mt-3 text-center text-xs text-slate-500">Logo aktif saat ini. Unggah file baru untuk menggantikannya.</figcaption>
                        </figure>
                    @endif
                    <div class="mt-5">
                        <x-image-upload label="Unggah logo baru" name="logo" :preserve-alpha="true" />
                    </div>
                </aside>
            </div>

            <x-form-actions note="Perubahan identitas langsung terlihat di portal dan dokumen baru." :cancel-href="route('portal.dashboard', ['role' => $role])" submit-label="Simpan perubahan" submit-icon="check" />
        </form>
    </div>
</x-layouts.portal>
