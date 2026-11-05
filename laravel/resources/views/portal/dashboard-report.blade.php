@php
    $isReceivables = ($definition['report'] ?? null) === 'receivables';
@endphp
<x-layouts.portal :role="$role" :title="$definition['title']">
    <div class="mx-auto max-w-7xl space-y-5 lg:space-y-6">
        <x-page-header icon="{{ $isReceivables ? 'clock' : 'chart-bar' }}" eyebrow="Detail dashboard" :title="$definition['title']" :description="$definition['description']">
            <x-slot:actions>
                <flux:button :href="route('portal.dashboard', ['role' => $role])" variant="outline" icon="arrow-left" data-safe-back>Kembali ke dashboard</flux:button>
                @if ($isReceivables && in_array($role, ['admin', 'teller'], true))
                    <flux:button :href="route('portal.loans.index', ['role' => $role, 'status' => 'aktif'])" variant="primary" icon="calendar-days">Buka daftar angsuran</flux:button>
                @endif
            </x-slot:actions>
        </x-page-header>

        @if ($isReceivables)
            <section class="grid gap-3 sm:grid-cols-3">
                <x-stat-card label="Tagihan terbuka" :value="(string) $definition['rows']->count()" hint="Angsuran belum lunas" tone="amber" icon="queue-list" />
                <x-stat-card label="Total nominal" :value="'Rp'.number_format((float) $definition['rows']->sum(fn ($row) => (float) ($row->nominal ?? 0)), 0, ',', '.')" hint="Pokok + bunga" tone="rose" icon="banknotes" />
                <x-stat-card label="Jatuh tempo terdekat" :value="$definition['rows']->min('tanggal_jatuh_tempo') ? \Carbon\Carbon::parse($definition['rows']->min('tanggal_jatuh_tempo'))->translatedFormat('d M Y') : '—'" hint="Dari daftar di bawah" tone="sky" icon="calendar-days" />
            </section>

            <section class="space-y-3">
                @forelse ($definition['rows'] as $row)
                    @php
                        $href = ! empty($row->loan_id)
                            ? route('portal.loans.show', ['role' => $role, 'loan' => $row->loan_id, 'focus' => 'angsuran', 'highlight' => $row->installment_id ?? null])
                            : null;
                        $due = filled($row->tanggal_jatuh_tempo ?? null) ? \Carbon\Carbon::parse($row->tanggal_jatuh_tempo) : null;
                        $overdue = $due && $due->isPast() && ($row->status ?? '') !== 'lunas';
                    @endphp
                    <a @if($href) href="{{ $href }}" @endif @class([
                        'block rounded-2xl border bg-white p-4 transition',
                        'border-rose-200 hover:border-rose-300 hover:bg-rose-50/40' => $overdue,
                        'border-slate-200 hover:border-teal-200 hover:bg-teal-50/40' => ! $overdue,
                        'pointer-events-none' => ! $href,
                    ])>
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="font-semibold text-slate-950">{{ $row->kode_pinjaman ?? '—' }} · angsuran ke-{{ $row->angsuran_ke ?? '—' }}</p>
                                <p class="mt-1 text-sm text-slate-600">{{ $row->nama_lengkap ?? '—' }} <span class="text-slate-400">·</span> {{ $row->kode_anggota ?? '—' }}</p>
                            </div>
                            <div class="text-right">
                                <p class="font-semibold text-slate-950">Rp{{ number_format((float) ($row->nominal ?? 0), 0, ',', '.') }}</p>
                                <p @class(['mt-1 text-xs font-medium', 'text-rose-700' => $overdue, 'text-slate-500' => ! $overdue])>
                                    {{ $due ? $due->translatedFormat('d M Y') : '—' }}
                                    @if ($overdue) · terlambat @endif
                                </p>
                            </div>
                        </div>
                        <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
                            <x-status-badge :status="$row->status ?? 'belum'" />
                            @if ($href)
                                <span class="inline-flex items-center gap-1 text-sm font-medium text-teal-700">Buka angsuran <flux:icon name="arrow-right" class="size-4" /></span>
                            @endif
                        </div>
                    </a>
                @empty
                    <x-empty-state class="rounded-2xl border border-slate-200 bg-white py-12" :title="$definition['empty']" icon="inbox" />
                @endforelse
            </section>
        @else
            <section class="ui-panel overflow-hidden">
                <div class="ui-table-heading">
                    <div class="ui-table-title">
                        <span class="ui-table-title-mark"><flux:icon name="funnel" class="size-4" /></span>
                        <h2>Filter dashboard diterapkan</h2>
                    </div>
                    <span class="ui-table-count">{{ $definition['rows']->count() }} baris</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[42rem] text-sm">
                        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                            <tr>@foreach ($definition['headers'] as $header)<th class="px-5 py-3">{{ $header }}</th>@endforeach</tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse ($definition['rows'] as $row)
                                <tr>
                                    @foreach ((array) $row as $key => $value)
                                        @continue(in_array($key, ['loan_id', 'installment_id'], true))
                                        <td class="px-5 py-3 text-slate-700">{{ is_numeric($value) ? number_format((float) $value, 0, ',', '.') : ($value ?: '—') }}</td>
                                    @endforeach
                                </tr>
                            @empty
                                <tr><td colspan="{{ count($definition['headers']) }}"><x-empty-state class="border-0 bg-transparent py-12" :title="$definition['empty']" icon="inbox" /></td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
    </div>
</x-layouts.portal>
