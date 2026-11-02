<x-layouts.public title="Akun saya — SIMKEU">
    <main id="content" class="mx-auto max-w-5xl px-4 py-8 sm:px-6 sm:py-12">
        <a href="{{ route('portal.dashboard', ['role' => auth()->user()->role]) }}" class="text-sm font-semibold text-teal-300 underline-offset-4 hover:underline">← Kembali ke portal</a>
        <div class="mt-5 grid gap-6 lg:grid-cols-[1.1fr_.9fr]">
            <section class="rounded-3xl border border-white/10 bg-white/5 p-6 shadow-2xl shadow-slate-950/20 sm:p-8">
                <p class="text-sm font-semibold text-teal-200">Akun saya</p>
                <h1 class="mt-2 text-2xl font-semibold text-white">Profil dan kontak</h1>
                <p class="mt-2 text-sm leading-6 text-slate-300">Pastikan informasi kontak tetap akurat agar pemberitahuan penting dapat diterima.</p>
                @if (session('status'))
                    <div class="mt-6 rounded-xl border border-emerald-300/30 bg-emerald-300/10 p-4 text-sm text-emerald-100" role="status">{{ session('status') }}</div>
                @endif
                <form method="POST" action="{{ route('profile.update') }}" class="mt-6 grid gap-5">
                    @csrf
                    @method('PUT')
                    <flux:field><flux:label class="text-slate-200">Nama lengkap</flux:label><flux:input name="nama_lengkap" :value="old('nama_lengkap', auth()->user()->nama_lengkap)" required /><flux:error name="nama_lengkap" /></flux:field>
                    <flux:field><flux:label class="text-slate-200">Email</flux:label><flux:input type="email" name="email" :value="old('email', auth()->user()->email)" autocomplete="email" /><flux:description class="text-slate-400">Diperlukan untuk pengaturan ulang kata sandi.</flux:description><flux:error name="email" /></flux:field>
                    <flux:field><flux:label class="text-slate-200">Nomor telepon</flux:label><flux:input type="tel" name="telepon" :value="old('telepon', auth()->user()->telepon)" autocomplete="tel" /><flux:error name="telepon" /></flux:field>
                    <flux:button type="submit" variant="primary">Simpan profil</flux:button>
                </form>
            </section>
            <section class="rounded-3xl border border-white/10 bg-slate-900/70 p-6 sm:p-8">
                <p class="text-sm font-semibold text-teal-200">Keamanan akun</p>
                <h2 class="mt-2 text-xl font-semibold text-white">Ubah kata sandi</h2>
                <form method="POST" action="{{ route('profile.password.update') }}" class="mt-6 grid gap-5">
                    @csrf
                    @method('PUT')
                    <flux:field><flux:label class="text-slate-200">Kata sandi saat ini</flux:label><flux:input type="password" name="current_password" autocomplete="current-password" required /><flux:error name="current_password" /></flux:field>
                    <flux:field><flux:label class="text-slate-200">Kata sandi baru</flux:label><flux:input type="password" name="password" autocomplete="new-password" required /><flux:error name="password" /></flux:field>
                    <flux:field><flux:label class="text-slate-200">Konfirmasi kata sandi baru</flux:label><flux:input type="password" name="password_confirmation" autocomplete="new-password" required /></flux:field>
                    <flux:button type="submit" variant="primary">Perbarui kata sandi</flux:button>
                </form>
            </section>
        </div>
    </main>
</x-layouts.public>
