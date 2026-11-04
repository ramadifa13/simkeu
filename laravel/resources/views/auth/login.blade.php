<x-layouts.public title="Masuk — SIMKEU">
    <main id="content" class="ui-login-stage relative grid min-h-screen overflow-hidden lg:grid-cols-[1.05fr_.95fr]">
        <section class="relative hidden overflow-hidden px-12 py-10 text-white lg:flex lg:flex-col lg:justify-between">
            <x-brand-logo :href="route('landing')" size="lg" class="self-start" />
            <div class="max-w-xl animate-rise-in">
                <p class="text-xs font-bold uppercase tracking-[.2em] text-amber-200">Portal koperasi</p>
                <h1 class="mt-5 text-5xl font-semibold leading-[1.08] tracking-tight">Satu akun untuk akses yang <span class="bg-gradient-to-r from-teal-200 via-sky-200 to-cyan-100 bg-clip-text text-transparent">lebih jelas.</span></h1>
                <p class="mt-6 text-lg leading-8 text-slate-200">Masuk sesuai peran Anda untuk mengelola operasional, melayani transaksi, atau memantau informasi keuangan pribadi.</p>
                <div class="mt-10 grid max-w-md gap-3">
                    <div class="flex gap-3 rounded-2xl border border-white/15 bg-white/10 p-4 backdrop-blur"><span class="grid size-9 shrink-0 place-items-center rounded-xl bg-teal-300/20 text-teal-100"><flux:icon name="shield-check" class="size-4" /></span><p class="text-sm leading-6 text-slate-100"><strong class="block text-white">Akses sesuai peran</strong>Menu dan data disesuaikan untuk Admin, Teller, atau Anggota.</p></div>
                    <div class="flex gap-3 rounded-2xl border border-white/15 bg-white/10 p-4 backdrop-blur"><span class="grid size-9 shrink-0 place-items-center rounded-xl bg-sky-300/20 text-sky-100"><flux:icon name="lock-closed" class="size-4" /></span><p class="text-sm leading-6 text-slate-100"><strong class="block text-white">Akun tetap terjaga</strong>Hubungi Teller atau Admin bila memerlukan bantuan akses.</p></div>
                </div>
            </div>
            <p class="text-sm text-slate-300">Sistem informasi keuangan koperasi</p>
        </section>
        <section class="relative grid place-items-center px-5 py-8 sm:px-8 lg:px-12">
            <div class="ui-login-card w-full max-w-md animate-rise-in animation-delay-150 rounded-3xl p-6 sm:p-8">
                <x-brand-logo :href="route('landing')" :on-dark="false" size="md" class="lg:hidden" />
                <div class="mt-7 lg:mt-0">
                    <p class="text-xs font-bold uppercase tracking-[.18em] text-teal-700">Masuk ke portal</p>
                    <h2 class="mt-3 text-3xl font-semibold tracking-tight text-slate-950">Selamat datang kembali.</h2>
                    <p class="mt-2 text-sm leading-6 text-slate-600">Gunakan username dan kata sandi akun aktif Anda.</p>
                </div>
                <form method="POST" action="{{ route('login.store') }}" class="ui-login-form mt-7 grid gap-4" aria-label="Form masuk">
                    @csrf
                    <flux:field><flux:label>Username</flux:label><flux:input type="text" name="username" value="{{ old('username') }}" autocomplete="username" placeholder="Masukkan username" required autofocus /><flux:error name="username" /></flux:field>
                    <flux:field><flux:label>Kata sandi</flux:label><flux:input type="password" name="password" autocomplete="current-password" placeholder="Masukkan kata sandi" required /><flux:error name="password" /></flux:field>
                    <label class="mt-1 flex items-center gap-2 text-sm text-slate-600"><input type="checkbox" name="remember" value="1" class="rounded border-slate-300 text-teal-600"> Ingat perangkat ini</label>
                    <flux:button type="submit" variant="primary" class="mt-2 w-full">Masuk ke portal</flux:button>
                </form>
                <div class="mt-6 rounded-2xl border border-sky-100 bg-gradient-to-br from-teal-50 to-sky-50 p-4">
                    <p class="text-sm font-semibold text-slate-900">Butuh bantuan akun?</p>
                    <p class="mt-1 text-sm leading-6 text-slate-600">Reset kata sandi hanya dapat dilakukan oleh Teller atau Admin koperasi.</p>
                </div>
            </div>
        </section>
    </main>
</x-layouts.public>
