<x-layouts.portal :role="$role" title="Validasi data legacy">
    <div class="mx-auto max-w-7xl space-y-6">
        <x-page-header icon="shield-check" eyebrow="UAT Fase 6" title="Validasi parity data" description="Bandingkan ringkasan finansial Laravel dengan salinan database PHP native. Halaman ini hanya melakukan pembacaan.">
            <x-slot:actions>
                <flux:button :href="route('portal.reports.index', ['role' => $role])" variant="outline" icon="chart-bar">Pusat laporan</flux:button>
            </x-slot:actions>
        </x-page-header>

        <div class="rounded-2xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900">
            <span class="font-semibold">Status koneksi:</span> {{ $report['native_status'] }}
        </div>

        <div class="grid gap-4 md:grid-cols-3">
            @foreach(['simpanan' => 'Saldo simpanan', 'kas' => 'Saldo kas', 'waserda' => 'Total Waserda'] as $key => $label)
                <x-stat-card :label="$label" :value="'Rp'.number_format((float) $report['laravel'][$key]['total'], 0, ',', '.')" :hint="$report['laravel'][$key]['count'].' baris pada Laravel'" tone="teal" :icon="$key === 'kas' ? 'wallet' : ($key === 'waserda' ? 'shopping-bag' : 'banknotes')" />
            @endforeach
        </div>

        @if($report['native'])
            <x-data-table title="Hasil perbandingan" :count="count($report['comparison'])" :sortable="false" :columns="['bagian' => 'Cakupan', 'laravel' => 'Laravel', 'native' => 'Native', 'status' => 'Status']" :mobile-hidden="[]">
                @foreach($report['comparison'] as $comparison)
                    <tr class="hover:bg-slate-50/80">
                        <td class="px-5 py-3 font-semibold text-slate-800">{{ $comparison['label'] }}</td>
                        <td class="px-5 py-3 text-slate-700">{{ $comparison['laravel']['count'] }} baris · <x-money :amount="$comparison['laravel']['total']" />@if($comparison['key'] === 'kas')<span class="block text-xs text-slate-500">Saldo akhir: <x-money :amount="$comparison['laravel']['last_balance']" /></span>@endif</td>
                        <td class="px-5 py-3 text-slate-700">{{ $comparison['native']['count'] }} baris · <x-money :amount="$comparison['native']['total']" />@if($comparison['key'] === 'kas')<span class="block text-xs text-slate-500">Saldo akhir: <x-money :amount="$comparison['native']['last_balance']" /></span>@endif</td>
                        <td class="px-5 py-3"><x-status-badge :status="$comparison['same'] ? 'sesuai' : 'perlu ditinjau'" /></td>
                    </tr>
                @endforeach
            </x-data-table>

            @if(count($report['account_differences']))
                <x-data-table title="Selisih rekening simpanan" :count="count($report['account_differences'])" :sortable="false" :columns="['rekening' => 'Rekening', 'laravel' => 'Laravel', 'native' => 'Native', 'selisih' => 'Selisih']" :mobile-hidden="[]">
                    @foreach($report['account_differences'] as $difference)
                        <tr class="hover:bg-amber-50/60">
                            <td class="px-5 py-3 font-mono text-xs font-semibold text-slate-700">{{ $difference['rekening'] }}</td>
                            <td class="px-5 py-3"><x-money :amount="$difference['laravel']" /></td>
                            <td class="px-5 py-3"><x-money :amount="$difference['native']" /></td>
                            <td class="px-5 py-3 font-semibold text-amber-800"><x-money :amount="$difference['selisih']" /></td>
                        </tr>
                    @endforeach
                </x-data-table>
            @else
                <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">Saldo setiap rekening simpanan yang terbaca saat ini sesuai dengan database referensi.</div>
            @endif
        @else
            <x-empty-state title="Perbandingan native belum dapat dijalankan" description="Konfigurasikan koneksi database referensi native yang terpisah, lalu muat ulang halaman. Ringkasan Laravel di atas tetap dapat dipakai untuk pemeriksaan awal." icon="shield-check" />
        @endif

        <div class="rounded-2xl border border-slate-200 bg-white p-5 text-sm text-slate-600 shadow-sm">
            <p class="font-semibold text-slate-800">Yang masih wajib diuji sebelum Fase 6 ditutup</p>
            <ul class="mt-2 list-disc space-y-1 pl-5">
                <li>Saldo tiap rekening dan urutan mutasi kas pada salinan data native.</li>
                <li>Filter, pagination, dan CSV pada setiap jenis laporan.</li>
                <li>Skenario transaksi bersamaan serta selisih yang perlu diberi penjelasan bisnis.</li>
            </ul>
        </div>
    </div>
</x-layouts.portal>
