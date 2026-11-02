<x-layouts.portal :role="$role" title="Dashboard {{ ucfirst($role) }}">
    <div class="mx-auto max-w-7xl">
        <x-page-header eyebrow="Portal {{ ucfirst($role) }}" title="Selamat datang di SIMKEU." description="Statistik dan aksi operasional akan dihubungkan setelah modul data legacy selesai dimigrasikan.">
            <x-slot:actions><flux:button disabled>Belum ada aksi cepat</flux:button></x-slot:actions>
        </x-page-header>
        <div class="mt-10 grid gap-5 sm:grid-cols-2 xl:grid-cols-4">@foreach (['Ringkasan hari ini', 'Antrean proses', 'Status finansial', 'Aktivitas terbaru'] as $item)<x-stat-card :label="$item" hint="Menunggu koneksi data legacy." />@endforeach</div>
    </div>
</x-layouts.portal>
