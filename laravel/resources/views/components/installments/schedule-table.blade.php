@props(['rows' => [], 'totalInstallments' => null])

<div {{ $attributes->merge(['class' => 'ui-panel overflow-hidden']) }}>
    <div class="ui-table-heading">
        <div class="ui-table-title"><span class="ui-table-title-mark"><flux:icon name="calendar-days" class="size-4" /></span>Jadwal angsuran @if ($totalInstallments)<span class="ui-table-count">{{ $totalInstallments }} cicilan</span>@endif</div>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full min-w-[42rem] text-left text-sm">
            <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-4 py-3">Ke</th><th class="px-4 py-3">Jatuh tempo</th><th class="px-4 py-3 text-right">Pokok</th><th class="px-4 py-3 text-right">Bunga</th><th class="px-4 py-3 text-right">Total</th><th class="px-4 py-3">Status</th><th class="px-4 py-3 text-right">Aksi</th></tr></thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($rows as $row)
                    <tr>
                        <td class="px-4 py-3 font-semibold">{{ data_get($row, 'number') }}@if ($totalInstallments)<small class="ml-1 text-slate-400">/{{ $totalInstallments }}</small>@endif</td>
                        <td class="px-4 py-3"><span class="block">{{ data_get($row, 'due_date') }}</span>@if (data_get($row, 'note'))<small @class(['text-amber-700' => data_get($row, 'note_tone') === 'warning', 'text-emerald-700' => data_get($row, 'note_tone') === 'success', 'text-slate-500' => ! in_array(data_get($row, 'note_tone'), ['warning', 'success'])])>{{ data_get($row, 'note') }}</small>@endif</td>
                        <td class="px-4 py-3 text-right">{{ data_get($row, 'principal') }}</td><td class="px-4 py-3 text-right">{{ data_get($row, 'interest') }}</td><td class="px-4 py-3 text-right font-semibold">{{ data_get($row, 'total') }}</td>
                        <td class="px-4 py-3"><x-status-badge :status="data_get($row, 'status')" /></td>
                        <td class="px-4 py-3 text-right"><x-action-menu :label="'Aksi angsuran ke-'.data_get($row, 'number')">@foreach (data_get($row, 'actions', []) as $action)<button type="button" @class(['block w-full rounded-lg px-3 py-2 text-left text-sm hover:bg-slate-50' => true, 'text-rose-700 hover:bg-rose-50' => data_get($action, 'danger'), 'text-slate-700 hover:bg-teal-50' => ! data_get($action, 'danger')])>{{ data_get($action, 'label') }}</button>@endforeach</x-action-menu></td>
                    </tr>
                @empty
                    <tr><td colspan="7"><x-empty-state title="Belum ada jadwal angsuran" description="Jadwal akan tampil setelah pinjaman disetujui dan dicairkan." /></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
