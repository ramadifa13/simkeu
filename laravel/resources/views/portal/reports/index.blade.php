<x-layouts.portal :role="$role" title="Pusat laporan">
    @php($activeFilter = collect($filters)->except(['jenis', 'per_page'])->filter(fn ($value) => filled($value))->isNotEmpty())
    @php($reportLabels = ['simpanan' => 'Simpanan', 'kas' => 'Kas', 'anggota' => 'Anggota', 'waserda' => 'Waserda', 'denda' => 'Denda', 'pinjaman' => 'Pinjaman', 'angsuran' => 'Angsuran', 'lunas' => 'Lunas', 'harian' => 'Harian teller', 'transaksi' => 'Transaksi gabungan'])
    @php($tabs = collect($availableTypes ?? array_keys($reportLabels))->mapWithKeys(fn ($key) => [$key => $reportLabels[$key] ?? ucfirst($key)]))

    <div class="mx-auto max-w-7xl space-y-6">
        <x-page-header icon="chart-bar" eyebrow="Laporan operasional" :title="$definition['title']" :description="$definition['description']">
            <x-slot:actions>
                @can('view-admin-reports')
                    <flux:button :href="route('portal.reports.parity', ['role' => $role])" variant="outline" icon="shield-check">Validasi legacy</flux:button>
                @endcan
                <flux:button :href="route('portal.reports.export', array_merge(['role' => $role], request()->query()))" variant="outline" icon="arrow-down-tray">Ekspor CSV</flux:button>
                <flux:button :href="route('portal.reports.export-excel', array_merge(['role' => $role], request()->query()))" variant="outline" icon="table-cells">Ekspor Excel</flux:button>
                <flux:button :href="route('portal.documents.report', array_merge(['role' => $role], request()->query()))" variant="outline" icon="document">PDF</flux:button>
            </x-slot:actions>
        </x-page-header>

        <div class="ui-report-tabs" aria-label="Jenis laporan">
            @foreach($tabs as $key => $label)
                <a href="{{ route('portal.reports.index', ['role' => $role, 'jenis' => $key]) }}" class="ui-report-tab{{ $filters['jenis'] === $key ? ' is-active' : '' }}" @if($filters['jenis'] === $key) aria-current="page" @endif>{{ $label }}</a>
            @endforeach
        </div>

        <x-data-table title="{{ $definition['title'] }}" :count="$rows->total()" :sortable="false" :columns="$definition['columns']" :mobile-hidden="array_slice(array_keys($definition['columns']), 2, max(0, count($definition['columns']) - 4))">
            <x-slot:filters>
                <form method="GET" class="grid gap-3 p-4 sm:grid-cols-2 {{ $filters['jenis'] === 'transaksi' ? 'lg:grid-cols-6' : 'lg:grid-cols-5' }} lg:items-end">
                    <input type="hidden" name="jenis" value="{{ $filters['jenis'] }}">
                    <flux:field class="lg:col-span-2"><flux:label>Pencarian</flux:label><flux:input type="search" name="q" :value="$filters['q'] ?? ''" placeholder="Cari kode, nama, atau keterangan" /></flux:field>
                    @if ($filters['jenis'] === 'transaksi')
                        <flux:field>
                            <flux:label>Sumber</flux:label>
                            <flux:select name="sumber">
                                <option value="">Semua sumber</option>
                                @foreach (['TABUNGAN' => 'Tabungan', 'PINJAMAN' => 'Pinjaman', 'WASERDA' => 'Waserda', 'KAS_LAIN' => 'Kas lain'] as $value => $label)
                                    <option value="{{ $value }}" @selected(($filters['sumber'] ?? '') === $value)>{{ $label }}</option>
                                @endforeach
                            </flux:select>
                        </flux:field>
                    @endif
                    <flux:field><flux:label>Tanggal dari</flux:label><flux:input type="date" name="tanggal_dari" :value="$filters['tanggal_dari'] ?? ''" /></flux:field>
                    <flux:field><flux:label>Tanggal sampai</flux:label><flux:input type="date" name="tanggal_sampai" :value="$filters['tanggal_sampai'] ?? ''" /></flux:field>
                    <div class="flex gap-2"><flux:button type="submit" variant="primary" class="flex-1">Terapkan</flux:button>@if($activeFilter)<flux:button :href="route('portal.reports.index', ['role' => $role, 'jenis' => $filters['jenis']])" variant="ghost">Reset</flux:button>@endif</div>
                </form>
            </x-slot:filters>

            @forelse($rows as $row)
                <tr class="hover:bg-slate-50/80">
                    @foreach($definition['columns'] as $key => $label)
                        <td class="whitespace-nowrap px-5 py-3 text-slate-700 {{ in_array($key, ['anggota', 'keterangan'], true) ? 'font-medium' : '' }}">
                            @if(in_array($key, $definition['money'], true))
                                <x-money :amount="data_get($row, $key)" />
                            @elseif(in_array($key, $definition['dates'], true) && data_get($row, $key))
                                {{ \Carbon\Carbon::parse(data_get($row, $key))->translatedFormat('d M Y, H:i') }}
                            @elseif($key === 'status')
                                <x-status-badge :status="data_get($row, $key)" />
                            @else
                                {{ data_get($row, $key) ?: '—' }}
                            @endif
                        </td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ count($definition['columns']) }}" class="p-4"><x-empty-state title="Data laporan tidak ditemukan" description="Ubah filter atau periode untuk melihat data lain." icon="chart-bar" /></td></tr>
            @endforelse

            <x-slot:footer><x-server-pagination :paginator="$rows" :query="collect(request()->query())->except(['page', 'per_page'])->all()" /></x-slot:footer>
        </x-data-table>
    </div>
</x-layouts.portal>
