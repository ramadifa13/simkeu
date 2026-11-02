<x-layouts.portal :role="$role" :title="$module['title']" :breadcrumbs="[['label' => 'Dashboard', 'href' => route('portal.dashboard', ['role' => $role])], ['label' => $module['title']]]">
    <section class="mx-auto max-w-3xl">
        <x-page-header eyebrow="Roadmap SIMKEU" :title="$module['title']" description="Modul ini sudah memiliki keputusan navigasi dan akses per peran, namun belum dibuka untuk transaksi." />
        <div class="mt-8 rounded-3xl border border-amber-200 bg-amber-50 p-6 text-amber-950 shadow-sm sm:p-8">
            <p class="text-sm font-semibold">Direncanakan pada Fase {{ $module['phase'] }}</p>
            <p class="mt-2 leading-7 text-amber-900">Kami menahan akses operasional sampai aturan bisnis, otorisasi, dan data legacy modul ini siap. Ini mencegah data baru tercatat sebelum prosesnya aman.</p>
            <div class="mt-6"><flux:button :href="route('portal.dashboard', ['role' => $role])" variant="primary">Kembali ke dashboard</flux:button></div>
        </div>
    </section>
</x-layouts.portal>
