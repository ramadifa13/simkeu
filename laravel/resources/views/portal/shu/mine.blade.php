<x-layouts.portal :role="$role" title="SHU saya">
    <div class="mx-auto max-w-5xl space-y-6">
        <x-page-header icon="wallet" eyebrow="Keuangan saya" title="SHU saya" description="Hak Sisa Hasil Usaha periode tahunan dan hak dari pinjaman lunas." />

        <x-data-table title="Hak periode" :count="$periodRows->count()" :sortable="false" :columns="['periode' => 'Periode', 'jumlah' => 'Hak', 'status' => 'Status bayar', 'periode_status' => 'Status periode', 'aksi' => 'Aksi']">
            @forelse($periodRows as $row)
                <tr>
                    <td class="px-5 py-3 font-semibold text-slate-900">{{ $row->nama_periode }}</td>
                    <td class="px-5 py-3"><x-money :amount="$row->total_shu" /></td>
                    <td class="px-5 py-3"><x-status-badge :status="$row->status_bayar" /></td>
                    <td class="px-5 py-3"><x-status-badge :status="$row->periode_status" /></td>
                    <td class="px-5 py-3 text-right">@if($row->status_bayar === 'sudah')<a href="{{ route('portal.documents.shu-receipt', ['role' => $role, 'entitlement' => $row->id]) }}" class="text-sm font-semibold text-teal-700">PDF</a>@endif</td>
                </tr>
            @empty
                <tr><td colspan="5" class="p-4"><x-empty-state title="Belum ada hak periode" description="Hak muncul setelah Admin menghitung dan mengesahkan periode SHU." icon="calculator" /></td></tr>
            @endforelse
        </x-data-table>

        <x-data-table title="Hak pinjaman" :count="$loanRows->count()" :sortable="false" :columns="['jenis' => 'Jenis', 'jumlah' => 'Hak', 'status' => 'Status']">
            @forelse($loanRows as $row)
                <tr>
                    <td class="px-5 py-3 font-semibold text-slate-900">{{ ucfirst($row->jenis) }}</td>
                    <td class="px-5 py-3"><x-money :amount="$row->jumlah" /></td>
                    <td class="px-5 py-3"><x-status-badge :status="$row->status_bayar" /></td>
                </tr>
            @empty
                <tr><td colspan="3" class="p-4"><x-empty-state title="Belum ada hak pinjaman" description="Hak tercatat otomatis saat pinjaman tanpa jaminan lunas." icon="banknotes" /></td></tr>
            @endforelse
        </x-data-table>
    </div>
</x-layouts.portal>
