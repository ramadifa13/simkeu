<x-layouts.portal :role="$role" title="Akun saya">
    <div class="mx-auto max-w-5xl space-y-6">
        <x-page-header
            icon="user-circle"
            eyebrow="Pusat akun"
            title="Profil dan keamanan akun"
            description="Kelola informasi kontak dan kata sandi Anda dalam satu halaman."
            :back-href="route('portal.dashboard', ['role' => $role])"
            back-label="Dashboard"
        />

        <x-validation-summary />

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1.08fr)_minmax(20rem,.92fr)]">
            <form method="POST" action="{{ route('profile.update') }}" enctype="multipart/form-data" class="ui-form-section space-y-5">
                @csrf
                @method('PUT')

                <div class="flex items-start gap-3 border-b border-slate-100 pb-4">
                    <span class="grid size-9 shrink-0 place-items-center rounded-xl bg-teal-50 text-teal-700"><flux:icon name="identification" class="size-4" /></span>
                    <div>
                        <h2>Profil dan kontak</h2>
                        <p>Informasi ini dipakai untuk identitas akun dan pemberitahuan koperasi.</p>
                    </div>
                </div>

                <x-required-hint />

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2 flex flex-wrap items-center gap-4 rounded-xl border border-slate-200 bg-slate-50/80 p-4">
                        @if (($avatar['url'] ?? null))
                            <img src="{{ $avatar['url'] }}?v={{ $avatar['version'] }}" alt="Foto profil" class="size-16 rounded-full object-cover ring-2 ring-white" />
                        @else
                            <span class="grid size-16 place-items-center rounded-full bg-teal-100 text-xl font-semibold text-teal-800">{{ mb_substr(auth()->user()->nama_lengkap ?? 'U', 0, 1) }}</span>
                        @endif
                        <div class="min-w-0 flex-1 space-y-2">
                            @if ($role === 'anggota')
                                <p class="text-sm font-semibold text-slate-900">Foto profil anggota</p>
                                <p class="text-sm text-slate-600">Dipakai dari <strong>foto pencairan</strong> (nasabah menerima uang). Foto diganti otomatis saat ada pencairan pinjaman baru yang aktif.</p>
                                @unless ($memberDisbursementPhoto)
                                    <p class="text-xs text-amber-700">Belum ada foto pencairan. Avatar akan muncul setelah pinjaman dicairkan.</p>
                                @endunless
                            @else
                                <x-image-upload label="Foto profil" name="foto_profil" :required="false" />
                                @if (filled(auth()->user()->foto_profil))
                                    <label class="inline-flex items-center gap-2 text-sm text-slate-600">
                                        <input type="checkbox" name="hapus_foto_profil" value="1" class="rounded border-slate-300 text-teal-600">
                                        Hapus foto profil saat ini
                                    </label>
                                @endif
                                <p class="text-xs text-slate-500">JPG, PNG, atau WebP; maksimal 10 MB.</p>
                            @endif
                        </div>
                    </div>
                    <flux:field class="sm:col-span-2">
                        <flux:label>Nama lengkap <x-required-mark /></flux:label>
                        <flux:input name="nama_lengkap" :value="old('nama_lengkap', auth()->user()->nama_lengkap)" required autocomplete="name" />
                        <flux:error name="nama_lengkap" />
                    </flux:field>
                    <flux:field>
                        <flux:label>Email</flux:label>
                        <flux:input type="email" name="email" :value="old('email', auth()->user()->email)" autocomplete="email" />
                        <flux:description>Opsional, untuk kontak operasional.</flux:description>
                        <flux:error name="email" />
                    </flux:field>
                    <flux:field>
                        <flux:label>Nomor telepon</flux:label>
                        <flux:input type="tel" name="telepon" :value="old('telepon', auth()->user()->telepon)" inputmode="tel" autocomplete="tel" />
                        <flux:error name="telepon" />
                    </flux:field>
                </div>

                <x-form-actions note="Perubahan profil hanya memengaruhi akun yang sedang masuk." submit-label="Simpan profil" />
            </form>

            <form method="POST" action="{{ route('profile.password.update') }}" class="ui-form-section space-y-5">
                @csrf
                @method('PUT')

                <div class="flex items-start gap-3 border-b border-slate-100 pb-4">
                    <span class="grid size-9 shrink-0 place-items-center rounded-xl bg-sky-50 text-sky-700"><flux:icon name="lock-closed" class="size-4" /></span>
                    <div>
                        <h2>Keamanan akun</h2>
                        <p>Gunakan kata sandi yang kuat dan jangan membagikannya kepada siapa pun.</p>
                    </div>
                </div>

                <x-required-hint />

                <div class="grid gap-4">
                    <flux:field>
                        <flux:label>Kata sandi saat ini <x-required-mark /></flux:label>
                        <flux:input type="password" name="current_password" autocomplete="current-password" required />
                        <flux:error name="current_password" />
                    </flux:field>
                    <flux:field>
                        <flux:label>Kata sandi baru <x-required-mark /></flux:label>
                        <flux:input type="password" name="password" autocomplete="new-password" required />
                        <flux:error name="password" />
                    </flux:field>
                    <flux:field>
                        <flux:label>Konfirmasi kata sandi baru <x-required-mark /></flux:label>
                        <flux:input type="password" name="password_confirmation" autocomplete="new-password" required />
                        <flux:error name="password_confirmation" />
                    </flux:field>
                </div>

                <x-form-actions note="Setelah tersimpan, gunakan kata sandi baru pada login berikutnya." submit-label="Perbarui kata sandi" />
            </form>
        </div>
    </div>
</x-layouts.portal>
