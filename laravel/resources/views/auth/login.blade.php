<x-layouts.public title="Masuk — SIMKEU">
    <main id="content" class="grid min-h-screen place-items-center px-5 py-10">
        <section class="w-full max-w-md rounded-3xl border border-white/10 bg-white/5 p-7 shadow-2xl shadow-teal-950/20 backdrop-blur sm:p-9">
            <a href="{{ route('landing') }}" class="flex items-center gap-3 font-semibold text-white"><span class="grid size-10 place-items-center rounded-xl bg-teal-300 text-lg font-black text-slate-950">S</span>SIMKEU</a>
            <h1 class="mt-9 text-2xl font-semibold text-white">Selamat datang kembali</h1>
            <p class="mt-2 text-sm leading-6 text-slate-300">Masuk dengan akun aktif Anda untuk melanjutkan ke portal yang sesuai.</p>
            @if (session('status'))<div class="mt-5 rounded-xl border border-emerald-300/30 bg-emerald-300/10 p-4 text-sm text-emerald-100" role="status">{{ session('status') }}</div>@endif
            <form method="POST" action="{{ route('login.store') }}" class="mt-7 grid gap-5" aria-label="Form masuk">@csrf
                <flux:field><flux:label class="text-slate-200">Username</flux:label><flux:input type="text" name="username" value="{{ old('username') }}" autocomplete="username" placeholder="Masukkan username" required autofocus /><flux:error name="username" /></flux:field>
                <flux:field><flux:label class="text-slate-200">Password</flux:label><flux:input type="password" name="password" autocomplete="current-password" placeholder="Masukkan password" required /><flux:error name="password" /></flux:field>
                <label class="flex items-center gap-2 text-sm text-slate-300"><input type="checkbox" name="remember" value="1" class="rounded border-slate-500 bg-slate-800 text-teal-300"> Ingat perangkat ini</label>
                <flux:button type="submit" variant="primary" class="w-full">Masuk</flux:button>
            </form>
            <a href="{{ route('password.request') }}" class="mt-6 block text-center text-sm font-semibold text-teal-200 underline-offset-4 hover:underline">Lupa kata sandi?</a>
        </section>
    </main>
</x-layouts.public>
