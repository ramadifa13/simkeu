<x-layouts.portal :role="$role" title="Pelunasan pinjaman">
    <div class="mx-auto max-w-7xl space-y-6">
        <x-page-header icon="check-badge" eyebrow="Pinjaman" title="Pelunasan pinjaman" description="Pastikan seluruh denda telah dibayar sebelum melunasi sisa pinjaman." :back-href="route('portal.loans.index', ['role' => $role])" />
        <x-loans.workflow :role="$role" active="settlement" />
        <x-data-table title="Pinjaman aktif" icon="check-badge" :count="$loans->total()" :sortable="false" :columns="['anggota' => 'Anggota', 'kode' => 'Pinjaman', 'sisa' => 'Sisa pokok', 'denda' => 'Sisa denda', 'actions' => 'Aksi']" :mobile-hidden="['kode', 'denda']">
            <x-slot:filters><form method="GET" class="flex flex-col gap-3 border-b border-slate-100 bg-slate-50/70 p-4 sm:flex-row sm:items-end"><flux:field class="min-w-0 flex-1"><flux:label>Pencarian</flux:label><flux:input type="search" name="q" :value="$filters['q'] ?? ''" placeholder="Nama anggota, kode anggota, atau pinjaman" /></flux:field><div class="flex gap-2"><flux:button type="submit" variant="primary">Cari</flux:button>@if(request()->filled('q'))<flux:button :href="route('portal.loans.settlements', ['role'=>$role])" variant="ghost">Reset</flux:button>@endif</div></form></x-slot:filters>
            @forelse ($loans as $loan)
                <tr>
                    <td class="px-5 py-3"><span class="font-semibold">{{ $loan->nama_lengkap }}</span><span class="block text-xs text-slate-500">{{ $loan->kode_anggota }}</span></td>
                    <td class="hidden px-5 py-3 font-mono text-xs md:table-cell">{{ $loan->kode_pinjaman }}</td>
                    <td class="px-5 py-3 font-semibold"><x-money :amount="$loan->sisa_pokok" /></td>
                    <td @class(['hidden px-5 py-3 md:table-cell', 'text-rose-700' => $loan->sisa_denda > 0, 'text-emerald-700' => $loan->sisa_denda <= 0])><x-money :amount="$loan->sisa_denda" /></td>
                    <td class="px-5 py-3 text-right"><x-action-menu :label="'Aksi pelunasan '.$loan->kode_pinjaman"><a href="{{ route('portal.loans.show', ['role' => $role, 'loan' => $loan->id, 'focus' => 'pelunasan']) }}" role="menuitem" class="block rounded-lg px-3 py-2 text-left text-sm font-medium text-slate-700 hover:bg-teal-50">Detail dan proses pelunasan</a></x-action-menu></td>
                </tr>
            @empty
                <tr><td colspan="5" class="p-5"><x-empty-state icon="check-circle" title="Tidak ada pinjaman aktif" description="Pelunasan hanya tersedia untuk pinjaman yang masih aktif." /></td></tr>
            @endforelse
            <x-slot:footer><x-server-pagination :paginator="$loans" :query="request()->query()" /></x-slot:footer>
        </x-data-table>
    </div>
</x-layouts.portal>
