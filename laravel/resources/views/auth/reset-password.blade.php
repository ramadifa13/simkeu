<x-layouts.public title="Kata sandi baru — SIMKEU">
    <main id="content" class="grid min-h-screen place-items-center px-5 py-10">
        <section class="w-full max-w-md rounded-3xl border border-white/10 bg-white/5 p-7 shadow-2xl shadow-teal-950/20 backdrop-blur sm:p-9">
            <h1 class="text-2xl font-semibold text-white">Buat kata sandi baru</h1>
            <p class="mt-2 text-sm leading-6 text-slate-300">Pilih kata sandi baru yang kuat dan hanya Anda yang mengetahuinya.</p>
            <form method="POST" action="{{ route('password.store') }}" class="mt-6 grid gap-5">@csrf
                <input type="hidden" name="token" value="{{ $request->route('token') }}">
                <flux:field><flux:label class="text-slate-200">Email</flux:label><flux:input type="email" name="email" :value="old('email', $request->email)" autocomplete="email" required autofocus /><flux:error name="email" /></flux:field>
                <flux:field><flux:label class="text-slate-200">Kata sandi baru</flux:label><flux:input type="password" name="password" autocomplete="new-password" required /><flux:error name="password" /></flux:field>
                <flux:field><flux:label class="text-slate-200">Konfirmasi kata sandi</flux:label><flux:input type="password" name="password_confirmation" autocomplete="new-password" required /></flux:field>
                <flux:button type="submit" variant="primary" class="w-full">Simpan kata sandi</flux:button>
            </form>
        </section>
    </main>
</x-layouts.public>
