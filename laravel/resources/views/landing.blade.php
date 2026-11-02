<x-layouts.public title="SIMKEU — Sistem Koperasi Digital">
    <main>
        <section class="relative overflow-hidden border-b border-white/10 bg-slate-950">
            <div class="absolute inset-0 bg-[radial-gradient(circle_at_top_right,_rgba(20,184,166,0.18),_transparent_38%),radial-gradient(circle_at_left,_rgba(59,130,246,0.14),_transparent_32%)]"></div>
            <div class="relative mx-auto flex min-h-[42rem] max-w-7xl flex-col px-6 py-6 lg:px-8">
                <nav class="flex items-center justify-between" aria-label="Navigasi utama">
                    <a href="{{ route('landing') }}" class="flex items-center gap-3 font-semibold tracking-tight text-white"><span class="grid size-10 place-items-center rounded-xl bg-teal-400 text-lg font-black text-slate-950">S</span><span>SIMKEU</span></a>
                    <a href="{{ route('login') }}" class="rounded-xl border border-white/15 px-4 py-2 text-sm font-semibold text-white transition hover:border-teal-300 hover:bg-white/5">Masuk</a>
                </nav>
                <div class="flex flex-1 items-center py-20 lg:py-28"><div class="max-w-3xl">
                    <p class="mb-5 inline-flex rounded-full border border-teal-300/25 bg-teal-300/10 px-3 py-1 text-sm font-medium text-teal-200">Koperasi yang lebih tertata, transparan, dan mudah diakses.</p>
                    <h1 class="max-w-3xl text-4xl font-semibold tracking-tight text-white sm:text-6xl">Kelola simpanan dan pinjaman dengan lebih percaya diri.</h1>
                    <p class="mt-6 max-w-2xl text-lg leading-8 text-slate-300">SIMKEU membantu pengurus, teller, dan anggota menjalankan layanan koperasi dalam satu pengalaman digital yang jelas dan aman.</p>
                    <div class="mt-10 flex flex-col gap-3 sm:flex-row"><a href="{{ route('login') }}" class="rounded-xl bg-teal-300 px-5 py-3 text-center text-sm font-bold text-slate-950 transition hover:bg-teal-200">Masuk ke portal</a><a href="#layanan" class="rounded-xl border border-white/15 px-5 py-3 text-center text-sm font-semibold text-white transition hover:bg-white/5">Lihat layanan</a></div>
                </div></div>
            </div>
        </section>
        <section id="layanan" class="bg-white px-6 py-20 text-slate-900 lg:px-8"><div class="mx-auto max-w-7xl">
            <p class="text-sm font-bold uppercase tracking-[0.2em] text-teal-700">Satu sistem, tiga portal</p><h2 class="mt-3 text-3xl font-semibold tracking-tight">Dirancang untuk pekerjaan koperasi sehari-hari.</h2>
            <div class="mt-10 grid gap-5 md:grid-cols-3">@foreach ([['Admin', 'Kendalikan operasional, laporan, SHU, dan pengaturan.'], ['Teller', 'Layani anggota, transaksi, pencairan, serta angsuran.'], ['Anggota', 'Pantau simpanan, pinjaman, cicilan, dan SHU secara mandiri.']] as [$title, $description])<article class="rounded-2xl border border-slate-200 p-6 shadow-sm"><div class="grid size-10 place-items-center rounded-xl bg-teal-50 font-bold text-teal-700">{{ Str::substr($title, 0, 1) }}</div><h3 class="mt-5 text-lg font-semibold">{{ $title }}</h3><p class="mt-2 leading-7 text-slate-600">{{ $description }}</p></article>@endforeach</div>
        </div></section>
    </main>
</x-layouts.public>
