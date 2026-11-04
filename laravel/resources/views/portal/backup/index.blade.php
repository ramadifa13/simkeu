<x-layouts.portal :role="$role" title="Backup data">
    <div class="mx-auto max-w-3xl space-y-6">
        <x-page-header icon="archive-box" eyebrow="Laporan & pengaturan" title="Backup operasional" description="ZIP berisi dump skema+data operasional. Tidak mencakup foto KTP/KK/TTD anggota. Unduhan sekali pakai ber-audit." />

        <section class="rounded-2xl border border-amber-200 bg-amber-50/70 p-5 text-sm text-amber-950">
            <p class="font-semibold">Batasan keamanan</p>
            <ul class="mt-2 list-disc space-y-1 pl-5">
                <li>Hanya Admin.</li>
                <li>File tidak dipublikasikan di folder publik web.</li>
                <li>Token unduhan sekali pakai; setelah diunduh arsip dihapus.</li>
                <li>Dokumen privat anggota/TTD tidak ikut dibundel.</li>
            </ul>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-5">
            <form method="POST" action="{{ route('portal.backup.store', ['role' => $role]) }}">
                @csrf
                <x-form-actions submit-label="Buat backup sekarang" note="Proses mencatat audit generate." />
            </form>
            @if($latest)
                <div class="mt-5 rounded-xl border border-emerald-100 bg-emerald-50/60 p-4">
                    <p class="font-semibold text-emerald-900">Backup siap</p>
                    <p class="mt-1 text-sm text-emerald-800">{{ $latest['tables'] }} tabel · {{ number_format(($latest['bytes'] ?? 0) / 1024, 1) }} KB</p>
                    <flux:button class="mt-3" :href="route('portal.backup.download', ['role' => $role, 'token' => $latest['token']])" variant="primary" icon="arrow-down-tray">Unduh sekali</flux:button>
                </div>
            @endif
        </section>
    </div>
</x-layouts.portal>
