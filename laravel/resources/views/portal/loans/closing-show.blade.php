@php
    $monthNames = [1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'];
    [$year, $monthNum] = array_pad(explode('-', (string) $period->bulan), 2, null);
    $periodLabel = ($monthNames[(int) $monthNum] ?? $monthNum).' '.$year;
@endphp
<x-layouts.portal :role="$role" title="Rincian closing pinjaman">
    <div class="mx-auto max-w-6xl space-y-5">
        <x-page-header
            icon="lock-closed"
            eyebrow="Closing angsuran · {{ $periodLabel }}"
            title="Rincian closing"
            description="Snapshot pembayaran angsuran yang tercatat pada periode ini."
            :back-href="route('portal.loans.closing.index', ['role' => $role])"
        >
            <x-slot:actions><x-status-badge :status="$period->status" /></x-slot:actions>
        </x-page-header>

        <x-loans.workflow :role="$role" active="closing" />

        <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <x-stat-card label="Periode" :value="$periodLabel" icon="calendar-days" />
            <x-stat-card label="Jumlah cicilan" :value="$period->jumlah_pembayaran" icon="receipt-percent" />
            <x-stat-card label="Total closing" :value="'Rp '.number_format($period->total_pembayaran, 0, ',', '.')" icon="banknotes" />
            <x-stat-card label="Ditutup oleh" :value="$period->nama_penutup ?? '—'" icon="user" />
        </section>

        <x-form-section title="Catatan periode" description="Informasi proses closing dan pembukaan kembali.">
            <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <dt class="text-xs text-slate-500">Waktu ditutup</dt>
                    <dd class="mt-1 text-sm font-medium text-slate-900">
                        {{ $period->ditutup_at ? \Carbon\Carbon::parse($period->ditutup_at)->translatedFormat('d M Y, H:i') : '—' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-500">Waktu dibuka kembali</dt>
                    <dd class="mt-1 text-sm font-medium text-slate-900">
                        {{ $period->dibuka_at ? \Carbon\Carbon::parse($period->dibuka_at)->translatedFormat('d M Y, H:i') : '—' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-500">Dibuka oleh</dt>
                    <dd class="mt-1 text-sm font-medium text-slate-900">{{ $period->nama_pembuka ?? '—' }}</dd>
                </div>
                <div class="sm:col-span-2 lg:col-span-4">
                    <dt class="text-xs text-slate-500">Catatan</dt>
                    <dd class="mt-1 whitespace-pre-line text-sm leading-6 text-slate-700">
                        {{ $period->catatan ?: 'Tidak ada catatan.' }}
                    </dd>
                </div>
            </dl>
        </x-form-section>

        <x-data-table
            title="Cicilan dalam snapshot"
            icon="receipt-percent"
            :count="$installments->total()"
            :sortable="false"
            :columns="[
                'anggota' => 'Anggota',
                'pinjaman' => 'Kode pinjaman',
                'angsuran' => 'Angsuran',
                'tanggal_bayar' => 'Tanggal bayar',
                'nominal' => 'Nominal',
                'actions' => 'Aksi',
            ]"
            :mobile-hidden="['tanggal_bayar']"
        >
            <x-slot:filters>
                <form method="GET" class="flex flex-col gap-3 border-b border-slate-100 bg-slate-50/70 p-4 sm:flex-row sm:items-end">
                    <flux:field class="min-w-0 flex-1">
                        <flux:label>Pencarian rincian</flux:label>
                        <flux:input type="search" name="q" :value="$filters['q'] ?? ''" placeholder="Nama anggota atau kode pinjaman" />
                    </flux:field>
                    <div class="flex gap-2">
                        <flux:button type="submit" variant="primary">Cari</flux:button>
                        @if (request()->filled('q'))
                            <flux:button :href="route('portal.loans.closing.show', ['role' => $role, 'closing' => $period->id])" variant="ghost">Reset</flux:button>
                        @endif
                    </div>
                </form>
            </x-slot:filters>

            @forelse ($installments as $installment)
                <tr>
                    <td class="px-4 py-3">
                        <span class="font-semibold text-slate-900">{{ $installment->nama_lengkap }}</span>
                        <span class="block text-xs text-slate-500">{{ $installment->kode_anggota }}</span>
                    </td>
                    <td class="px-4 py-3 font-mono text-xs text-slate-600">{{ $installment->kode_pinjaman }}</td>
                    <td class="px-4 py-3 text-slate-700">Ke-{{ $installment->angsuran_ke }}</td>
                    <td class="hidden px-4 py-3 text-slate-700 md:table-cell">
                        {{ \Carbon\Carbon::parse($installment->tanggal_bayar)->translatedFormat('d M Y') }}
                    </td>
                    <td class="px-4 py-3 font-medium text-slate-900"><x-money :amount="$installment->nominal" /></td>
                    <td class="px-4 py-3 text-right">
                        <x-action-menu :label="'Aksi angsuran '.$installment->kode_pinjaman">
                            <a
                                href="{{ route('portal.loans.show', ['role' => $role, 'loan' => $installment->loan_id]) }}"
                                role="menuitem"
                                class="block rounded-lg px-3 py-2 text-left text-sm text-slate-700 hover:bg-teal-50"
                            >
                                Detail pinjaman
                            </a>
                        </x-action-menu>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="p-5">
                        <x-empty-state
                            icon="receipt-percent"
                            title="Snapshot tidak memiliki rincian"
                            description="Rincian pembayaran tidak tersedia untuk periode ini."
                        />
                    </td>
                </tr>
            @endforelse

            <x-slot:footer>
                <x-server-pagination :paginator="$installments" :query="request()->query()" />
            </x-slot:footer>
        </x-data-table>
    </div>
</x-layouts.portal>
