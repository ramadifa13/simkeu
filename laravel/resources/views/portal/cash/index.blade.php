<x-layouts.portal :role="$role" title="Buku kas">
    @php($activeFilter = collect($filters)->filter(fn ($value) => filled($value))->isNotEmpty())

    <div class="mx-auto max-w-7xl space-y-6">
        <x-page-header
            icon="wallet"
            eyebrow="Laporan operasional"
            title="Buku kas"
            description="Pantau seluruh mutasi kas yang dibentuk oleh transaksi operasional."
        />

        <div class="grid gap-4 sm:grid-cols-3">
            <x-stat-card label="Saldo kas" :value="'Rp'.number_format($balance, 0, ',', '.')" hint="Saldo mutasi terakhir" tone="teal" icon="wallet" />
            <x-stat-card label="Kas masuk" :value="'Rp'.number_format((float) $summary->masuk, 0, ',', '.')" hint="Sesuai filter aktif" tone="sky" icon="arrow-down-left" />
            <x-stat-card label="Kas keluar" :value="'Rp'.number_format((float) $summary->keluar, 0, ',', '.')" hint="Sesuai filter aktif" tone="rose" icon="arrow-up-right" />
        </div>

        <x-data-table
            title="Mutasi kas"
            :count="$entries->total()"
            :sortable="false"
            :columns="['tanggal' => 'Tanggal', 'sumber' => 'Sumber', 'anggota' => 'Anggota', 'jenis' => 'Mutasi', 'jumlah' => 'Nominal', 'saldo' => 'Saldo sesudah']"
            :mobile-hidden="['anggota', 'saldo']"
        >
            <x-slot:filters>
                <form method="GET" class="grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-6 lg:items-end">
                    <flux:field class="lg:col-span-2">
                        <flux:label>Pencarian</flux:label>
                        <flux:input type="search" name="q" :value="$filters['q'] ?? ''" placeholder="Kode, anggota, atau keterangan" />
                    </flux:field>
                    <flux:field>
                        <flux:label>Arus kas</flux:label>
                        <flux:select name="jenis">
                            <option value="">Semua arus</option>
                            <option value="masuk" @selected(($filters['jenis'] ?? '') === 'masuk')>Kas masuk</option>
                            <option value="keluar" @selected(($filters['jenis'] ?? '') === 'keluar')>Kas keluar</option>
                        </flux:select>
                    </flux:field>
                    <flux:field>
                        <flux:label>Sumber</flux:label>
                        <flux:select name="sumber">
                            <option value="">Semua sumber</option>
                            <option value="setoran" @selected(($filters['sumber'] ?? '') === 'setoran')>Setoran</option>
                            <option value="penarikan" @selected(($filters['sumber'] ?? '') === 'penarikan')>Penarikan</option>
                            <option value="waserda" @selected(($filters['sumber'] ?? '') === 'waserda')>Waserda</option>
                            <option value="angsuran" @selected(($filters['sumber'] ?? '') === 'angsuran')>Angsuran</option>
                            <option value="lainnya" @selected(($filters['sumber'] ?? '') === 'lainnya')>Lainnya</option>
                        </flux:select>
                    </flux:field>
                    <flux:field>
                        <flux:label>Tanggal dari</flux:label>
                        <flux:input type="date" name="tanggal_dari" :value="$filters['tanggal_dari'] ?? ''" />
                    </flux:field>
                    <flux:field>
                        <flux:label>Tanggal sampai</flux:label>
                        <flux:input type="date" name="tanggal_sampai" :value="$filters['tanggal_sampai'] ?? ''" />
                    </flux:field>
                    <div class="flex gap-2 sm:col-span-2 lg:col-span-6 lg:justify-end">
                        <flux:button type="submit" variant="primary" class="flex-1">Terapkan</flux:button>
                        @if($activeFilter)
                            <flux:button :href="route('portal.cash.index', ['role' => $role])" variant="ghost">Reset</flux:button>
                        @endif
                    </div>
                </form>
            </x-slot:filters>

            @forelse($entries as $entry)
                <tr class="hover:bg-slate-50/80">
                    <td class="whitespace-nowrap px-5 py-3 text-slate-600">{{ \Carbon\Carbon::parse($entry->tanggal)->translatedFormat('d M Y, H:i') }}</td>
                    <td class="px-5 py-3"><p class="font-mono text-xs font-semibold text-slate-700">{{ $entry->waserda_code ?: $entry->savings_code ?: 'KAS-'.str_pad((string) $entry->id, 5, '0', STR_PAD_LEFT) }}</p><p class="mt-1 text-xs capitalize text-slate-500">{{ str_replace('_', ' ', $entry->sumber) }}</p></td>
                    <td class="hidden px-5 py-3 text-slate-700 md:table-cell">{{ $entry->anggota_nama ? $entry->anggota_kode.' — '.$entry->anggota_nama : '—' }}</td>
                    <td class="px-5 py-3"><x-status-badge :status="$entry->jenis" /></td>
                    <td class="whitespace-nowrap px-5 py-3 font-semibold {{ $entry->jenis === 'masuk' ? 'text-emerald-700' : 'text-rose-700' }}">{{ $entry->jenis === 'masuk' ? '+' : '−' }}<x-money :amount="$entry->jumlah" /></td>
                    <td class="hidden whitespace-nowrap px-5 py-3 font-medium text-slate-700 md:table-cell"><x-money :amount="$entry->saldo_sesudah" /></td>
                </tr>
            @empty
                <tr><td colspan="6" class="p-4"><x-empty-state title="Mutasi kas tidak ditemukan" description="Ubah atau hapus filter untuk melihat mutasi kas lain." icon="wallet" /></td></tr>
            @endforelse

            <x-slot:footer>
                <x-server-pagination :paginator="$entries" :query="collect(request()->query())->except(['page', 'per_page'])->all()" />
            </x-slot:footer>
        </x-data-table>
    </div>
</x-layouts.portal>
