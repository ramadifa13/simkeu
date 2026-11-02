<x-layouts.public title="Masuk — SIMKEU">
    <main id="content" class="relative grid min-h-screen overflow-hidden bg-slate-950 lg:grid-cols-[1.05fr_.95fr]">
        <div class="absolute inset-0 bg-[radial-gradient(circle_at_16%_18%,rgba(45,212,191,.16),transparent_25rem),radial-gradient(circle_at_86%_84%,rgba(37,99,235,.2),transparent_28rem)]"></div>
        <section class="relative hidden overflow-hidden px-12 py-10 lg:flex lg:flex-col lg:justify-between">
            <a href="{{ route('landing') }}" class="inline-flex items-center gap-3 self-start font-semibold text-white"><span class="grid size-11 place-items-center rounded-2xl bg-teal-300 text-lg font-black text-slate-950">S</span>SIMKEU</a>
            <div class="max-w-xl animate-rise-in"><p class="text-xs font-bold uppercase tracking-[.2em] text-teal-200">Portal koperasi</p><h1 class="mt-5 text-5xl font-semibold leading-[1.08] tracking-tight text-white">Satu akun untuk akses yang <span class="text-teal-300">lebih jelas.</span></h1><p class="mt-6 text-lg leading-8 text-slate-300">Masuk sesuai peran Anda untuk mengelola operasional, melayani transaksi, atau memantau informasi keuangan pribadi.</p><div class="mt-10 grid max-w-md gap-3"><div class="flex gap-3 rounded-2xl border border-white/10 bg-white/[.055] p-4"><span class="grid size-9 shrink-0 place-items-center rounded-xl bg-teal-300/15 text-teal-200"><flux:icon name="shield-check" class="size-4" /></span><p class="text-sm leading-6 text-slate-300"><strong class="block text-white">Akses sesuai peran</strong>Menu dan data disesuaikan untuk Admin, Teller, atau Anggota.</p></div><div class="flex gap-3 rounded-2xl border border-white/10 bg-white/[.055] p-4"><span class="grid size-9 shrink-0 place-items-center rounded-xl bg-teal-300/15 text-teal-200"><flux:icon name="lock-closed" class="size-4" /></span><p class="text-sm leading-6 text-slate-300"><strong class="block text-white">Akun tetap terjaga</strong>Hubungi Teller atau Admin bila memerlukan bantuan akses.</p></div></div></div>
            <p class="text-sm text-slate-400">Sistem informasi keuangan koperasi</p>
        </section>
        <section class="relative grid place-items-center px-5 py-8 sm:px-8 lg:bg-white/[.035] lg:px-12">
            <div class="w-full max-w-md animate-rise-in animation-delay-150 rounded-3xl border border-white/10 bg-slate-900/75 p-6 shadow-2xl shadow-black/30 backdrop-blur-xl sm:p-8">
                <a href="{{ route('landing') }}" class="flex items-center gap-3 font-semibold text-white lg:hidden"><span class="grid size-10 place-items-center rounded-xl bg-teal-300 text-lg font-black text-slate-950">S</span>SIMKEU</a>
                <div class="mt-7 lg:mt-0"><p class="text-xs font-bold uppercase tracking-[.18em] text-teal-200">Masuk ke portal</p><h2 class="mt-3 text-3xl font-semibold tracking-tight text-white">Selamat datang kembali.</h2><p class="mt-2 text-sm leading-6 text-slate-300">Gunakan username dan kata sandi akun aktif Anda.</p></div>
                @if (session('status'))<div class="mt-5 rounded-xl border border-emerald-300/30 bg-emerald-300/10 p-3 text-sm text-emerald-100" role="status">{{ session('status') }}</div>@endif
                <form method="POST" action="{{ route('login.store') }}" class="ui-login-form mt-7 grid gap-4" aria-label="Form masuk">@csrf
                    <flux:field><flux:label class="text-slate-200">Username</flux:label><flux:input type="text" name="username" value="{{ old('username') }}" autocomplete="username" placeholder="Masukkan username" required autofocus /><flux:error name="username" /></flux:field>
                    <flux:field><flux:label class="text-slate-200">Kata sandi</flux:label><flux:input type="password" name="password" autocomplete="current-password" placeholder="Masukkan kata sandi" required /><flux:error name="password" /></flux:field>
                    <label class="mt-1 flex items-center gap-2 text-sm text-slate-300"><input type="checkbox" name="remember" value="1" class="rounded border-slate-500 bg-slate-800 text-teal-300"> Ingat perangkat ini</label>
                    <flux:button type="submit" variant="primary" class="mt-2 w-full">Masuk ke portal</flux:button>
                </form>
                <div class="mt-6 rounded-2xl border border-white/10 bg-white/[.045] p-4"><p class="text-sm font-semibold text-white">Butuh bantuan akun?</p><p class="mt-1 text-sm leading-6 text-slate-300">Reset kata sandi hanya dapat dilakukan oleh Teller atau Admin koperasi.</p></div>
            </div>
        </section>
    </main>
</x-layouts.public>
