@php
    $monthNames = [1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'];
    $formatMonth = static function (string $ym) use ($monthNames): string {
        [$year, $monthNum] = array_pad(explode('-', $ym), 2, null);

        return ($monthNames[(int) $monthNum] ?? $monthNum).' '.$year;
    };
    $monthLabel = $formatMonth($month);
@endphp
<x-layouts.portal :role="$role" title="Closing angsuran">
    <div class="mx-auto max-w-6xl space-y-5">
        <x-page-header icon="lock-closed" eyebrow="Pinjaman" title="Closing angsuran" :description="$role === 'admin' ? 'Periksa ringkasan, lalu kunci snapshot pembayaran bulanan yang sudah direkonsiliasi.' : 'Pantau status penutupan periode angsuran. Penguncian hanya dapat dilakukan Admin.'" :back-href="route('portal.loans.index', ['role' => $role])" />
        <x-loans.workflow :role="$role" active="closing" />
        <section @class(['grid gap-5', 'lg:grid-cols-[minmax(0,1fr)_minmax(18rem,.75fr)]' => $role === 'admin'])>
            <x-form-section title="Preview periode" description="Preview mencakup angsuran biasa yang tanggal bayar dan jatuh temponya berada pada bulan yang dipilih.">
                <form method="GET" class="grid gap-3 sm:grid-cols-[minmax(14rem,1fr)_auto] sm:items-end">
                    <x-month-picker name="bulan" label="Bulan laporan" :value="$month" :max="now()->format('Y-m')" :required="true" />
                    <flux:button type="submit" variant="primary">Tampilkan</flux:button>
                </form>
                <div class="mt-5 grid gap-3 sm:grid-cols-2">
                    <x-stat-card label="Jumlah cicilan" :value="$preview['jumlah']" icon="calendar-days" />
                    <x-stat-card label="Total pembayaran" :value="'Rp '.number_format($preview['total'], 0, ',', '.')" icon="banknotes" />
                </div>
            </x-form-section>
            @can('manage-installment-closing')
                <x-form-section title="Kunci periode" description="Snapshot menyimpan rincian pembayaran sebagai dasar closing bulan ini.">
                    <flux:modal.trigger name="confirm-close-period">
                        <flux:button variant="primary" icon="lock-closed" class="w-full">Kunci {{ $monthLabel }}</flux:button>
                    </flux:modal.trigger>
                </x-form-section>
            @endcan
        </section>
        @can('manage-installment-closing')
            <x-confirm-action-modal
                name="confirm-close-period"
                title="Konfirmasi closing periode"
                :description="'Periode '.$monthLabel.' akan mengunci '.$preview['jumlah'].' cicilan dengan total Rp '.number_format($preview['total'], 0, ',', '.').'. Pastikan rekonsiliasi sudah selesai.'"
                confirm-label="Kunci periode"
                :action="route('portal.loans.closing.close', ['role' => $role])"
            >
                <input type="hidden" name="bulan" value="{{ $month }}">
                <x-loans.note-field
                    name="catatan"
                    label="Catatan closing"
                    rows="2"
                    :maxlength="500"
                    placeholder="Catatan closing (opsional)"
                />
            </x-confirm-action-modal>
        @endcan

        <x-data-table title="Riwayat closing" icon="lock-closed" :count="$periods->total()" :sortable="false" :columns="['bulan' => 'Bulan', 'status' => 'Status', 'jumlah_pembayaran' => 'Cicilan', 'total_pembayaran' => 'Total', 'actions' => 'Aksi']" :mobile-hidden="['jumlah_pembayaran']">
            <x-slot:filters>
                <form method="GET" class="grid gap-3 border-b border-slate-100 bg-slate-50/70 p-4 sm:grid-cols-[minmax(12rem,1fr)_minmax(10rem,.8fr)_auto] sm:items-end">
                    <input type="hidden" name="bulan" value="{{ $month }}">
                    <flux:field>
                        <flux:label>Cari periode</flux:label>
                        <flux:input name="q" :value="$filters['q'] ?? ''" placeholder="YYYY-MM atau 2026" />
                    </flux:field>
                    <flux:field>
                        <flux:label>Status</flux:label>
                        <flux:select name="status">
                            <option value="">Semua status</option>
                            <option value="terkunci" @selected(($filters['status'] ?? '') === 'terkunci')>Terkunci</option>
                            <option value="dibuka" @selected(($filters['status'] ?? '') === 'dibuka')>Dibuka kembali</option>
                        </flux:select>
                    </flux:field>
                    <div class="flex gap-2">
                        <flux:button type="submit" variant="primary">Filter</flux:button>
                        @if (request()->filled('q') || request()->filled('status'))
                            <flux:button :href="route('portal.loans.closing.index', ['role' => $role, 'bulan' => $month])" variant="ghost">Reset</flux:button>
                        @endif
                    </div>
                </form>
            </x-slot:filters>
            @forelse ($periods as $period)
                @php($periodLabel = $formatMonth($period->bulan))
                <tr>
                    <td class="px-4 py-3 font-semibold text-slate-900">
                        {{ $periodLabel }}
                        <span class="mt-0.5 block text-xs font-medium text-slate-500">{{ $period->bulan }}</span>
                    </td>
                    <td class="px-4 py-3"><x-status-badge :status="$period->status" /></td>
                    <td class="hidden px-4 py-3 text-slate-700 md:table-cell">{{ number_format($period->jumlah_pembayaran) }}</td>
                    <td class="px-4 py-3 font-medium text-slate-900"><x-money :amount="$period->total_pembayaran" /></td>
                    <td class="px-4 py-3 text-right">
                        <x-action-menu :label="'Aksi closing '.$periodLabel">
                            <a href="{{ route('portal.loans.closing.show', ['role' => $role, 'closing' => $period->id]) }}" role="menuitem" class="block rounded-lg px-3 py-2 text-left text-sm text-slate-700 hover:bg-teal-50">Lihat rincian</a>
                            @can('manage-installment-closing')
                                @if ($period->status === 'terkunci')
                                    <flux:modal.trigger :name="'reopen-period-'.$period->id">
                                        <button type="button" role="menuitem" class="block w-full rounded-lg px-3 py-2 text-left text-sm text-rose-700 hover:bg-rose-50">Buka periode</button>
                                    </flux:modal.trigger>
                                @endif
                            @endcan
                        </x-action-menu>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="p-5">
                        <x-empty-state icon="lock-open" title="Belum ada riwayat closing" description="Pilih periode dan kunci setelah rekonsiliasi selesai." />
                    </td>
                </tr>
            @endforelse
            <x-slot:footer><x-server-pagination :paginator="$periods" :query="request()->query()" /></x-slot:footer>
        </x-data-table>
        @can('manage-installment-closing')
            @foreach ($periods as $period)
                @if ($period->status === 'terkunci')
                    @php($periodLabel = $formatMonth($period->bulan))
                    <x-confirm-action-modal
                        :name="'reopen-period-'.$period->id"
                        title="Buka kembali periode closing"
                        :description="'Periode '.$periodLabel.' akan dibuka. Angsuran pada periode tersebut dapat diproses kembali.'"
                        confirm-label="Buka periode"
                        variant="danger"
                        :action="route('portal.loans.closing.reopen', ['role' => $role])"
                    >
                        <input type="hidden" name="bulan" value="{{ $period->bulan }}">
                        <x-loans.note-field
                            name="catatan"
                            label="Alasan membuka periode"
                            placeholder="Alasan membuka periode (minimal 5 karakter)"
                            :required="true"
                            :minlength="5"
                            :maxlength="500"
                        />
                    </x-confirm-action-modal>
                @endif
            @endforeach
        @endcan
    </div>
</x-layouts.portal>
