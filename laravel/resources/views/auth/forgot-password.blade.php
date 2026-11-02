<x-layouts.public title="Atur ulang kata sandi — SIMKEU">
    <main id="content" class="grid min-h-screen place-items-center px-5 py-10">
        <section class="w-full max-w-md rounded-3xl border border-white/10 bg-white/5 p-7 shadow-2xl shadow-teal-950/20 backdrop-blur sm:p-9">
            <a href="{{ route('login') }}" class="text-sm font-semibold text-teal-200 underline-offset-4 hover:underline">← Kembali ke masuk</a>
            <h1 class="mt-8 text-2xl font-semibold text-white">Atur ulang kata sandi</h1>
            <p class="mt-2 text-sm leading-6 text-slate-300">Masukkan email akun. Jika terdaftar, kami kirimkan tautan pengaturan ulang.</p>
            @if (session('status'))<div class="mt-6 rounded-xl border border-emerald-300/30 bg-emerald-300/10 p-4 text-sm text-emerald-100" role="status">{{ session('status') }}</div>@endif
            <form method="POST" action="{{ route('password.email') }}" class="mt-6 grid gap-5">@csrf
                <flux:field><flux:label class="text-slate-200">Email</flux:label><flux:input type="email" name="email" :value="old('email')" autocomplete="email" required autofocus /><flux:error name="email" /></flux:field>
                <flux:button type="submit" variant="primary" class="w-full">Kirim tautan</flux:button>
            </form>
        </section>
    </main>
</x-layouts.public>
