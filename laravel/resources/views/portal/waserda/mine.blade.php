<x-layouts.portal :role="$role" title="Waserda saya">
    @php($activeFilter = collect($filters)->except('per_page')->filter(fn ($value) => filled($value))->isNotEmpty())
    <div class="mx-auto max-w-5xl space-y-6">
        <x-page-header icon="shopping-bag" eyebrow="Keuangan saya" title="Riwayat Waserda" description="Catatan belanja Anda di warung koperasi. Pembelian baru dicatat oleh Teller." />

        <div class="grid gap-4 sm:grid-cols-2">
            <x-stat-card label="Total belanja" :value="'Rp'.number_format((float) $totals->nominal, 0, ',', '.')" hint="Seluruh transaksi Anda" tone="teal" icon="banknotes" />
            <x-stat-card label="Jumlah transaksi" :value="(string) $totals->transaksi" hint="Riwayat tersimpan" tone="sky" icon="receipt-percent" />
        </div>

        <x-data-table title="Transaksi saya" :count="$transactions->total()" :sortable="false" :columns="['kode' => 'Kode / waktu', 'items' => 'Barang', 'metode' => 'Metode', 'total' => 'Total']" :mobile-hidden="['items']">
            <x-slot:filters>
                <form method="GET" class="grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4 lg:items-end">
                    <flux:field><flux:label>Tanggal dari</flux:label><flux:input type="date" name="tanggal_dari" :value="$filters['tanggal_dari'] ?? ''" /></flux:field>
                    <flux:field><flux:label>Tanggal sampai</flux:label><flux:input type="date" name="tanggal_sampai" :value="$filters['tanggal_sampai'] ?? ''" /></flux:field>
                    <div class="flex gap-2 lg:col-span-2"><flux:button type="submit" variant="primary" class="flex-1">Terapkan</flux:button>@if($activeFilter)<flux:button :href="route('portal.waserda.mine', ['role' => $role])" variant="ghost">Reset</flux:button>@endif</div>
                </form>
            </x-slot:filters>
            @forelse($transactions as $transaction)
                <tr class="hover:bg-slate-50/80">
                    <td class="whitespace-nowrap px-5 py-3"><p class="font-mono text-xs font-semibold text-slate-700">{{ $transaction->kode_transaksi }}</p><p class="mt-1 text-xs text-slate-500">{{ $transaction->created_at?->translatedFormat('d M Y, H:i') }}</p></td>
                    <td class="hidden px-5 py-3 text-slate-600 md:table-cell">{{ count($transaction->items ?? []) }} item<p class="mt-1 max-w-56 truncate text-xs text-slate-500">{{ collect($transaction->items ?? [])->pluck('nama')->join(', ') }}</p></td>
                    <td class="px-5 py-3"><x-status-badge :status="$transaction->metode_bayar" /></td>
                    <td class="whitespace-nowrap px-5 py-3 font-semibold text-emerald-700"><x-money :amount="$transaction->total_bayar" /></td>
                </tr>
            @empty
                <tr><td colspan="4" class="p-4"><x-empty-state title="Belum ada belanja Waserda" description="Transaksi yang dicatat Teller akan muncul di sini." icon="shopping-bag" /></td></tr>
            @endforelse
            <x-slot:footer><x-server-pagination :paginator="$transactions" :query="collect(request()->query())->except(['page', 'per_page'])->all()" /></x-slot:footer>
        </x-data-table>
    </div>
</x-layouts.portal>
