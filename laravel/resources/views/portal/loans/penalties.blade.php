<x-layouts.portal :role="$role" title="Denda pinjaman">
    <div class="mx-auto max-w-7xl space-y-6">
        <x-page-header icon="exclamation-triangle" eyebrow="Pinjaman" title="Tagihan denda" description="Catat denda terlebih dahulu sebelum pembayaran angsuran atau pelunasan." :back-href="route('portal.loans.index', ['role' => $role])" />
        <x-loans.workflow :role="$role" active="penalties" />
        <x-data-table title="Denda tertunggak" icon="exclamation-triangle" :count="$penalties->total()" :sortable="false" :columns="['anggota' => 'Anggota', 'pinjaman' => 'Pinjaman', 'angsuran' => 'Angsuran', 'denda' => 'Sisa denda', 'jatuh_tempo' => 'Jatuh tempo', 'actions' => 'Aksi']" :mobile-hidden="['pinjaman', 'jatuh_tempo']">
            <x-slot:filters><form method="GET" class="flex flex-col gap-3 border-b border-slate-100 bg-slate-50/70 p-4 sm:flex-row sm:items-end"><flux:field class="min-w-0 flex-1"><flux:label>Pencarian</flux:label><flux:input type="search" name="q" :value="$filters['q'] ?? ''" placeholder="Nama anggota, kode anggota, atau pinjaman" /></flux:field><div class="flex gap-2"><flux:button type="submit" variant="primary">Cari</flux:button>@if(request()->filled('q'))<flux:button :href="route('portal.loans.penalties', ['role'=>$role])" variant="ghost">Reset</flux:button>@endif</div></form></x-slot:filters>
            @forelse ($penalties as $penalty)
                <tr>
                    <td class="px-5 py-3"><span class="font-semibold">{{ $penalty->nama_lengkap }}</span><span class="block text-xs text-slate-500">{{ $penalty->kode_anggota }}</span></td>
                    <td class="hidden px-5 py-3 font-mono text-xs md:table-cell">{{ $penalty->kode_pinjaman }}</td>
                    <td class="px-5 py-3">Ke-{{ $penalty->angsuran_ke }}<span class="block text-xs text-rose-600">{{ $penalty->keterlambatan_hari }} hari terlambat</span></td>
                    <td class="px-5 py-3 font-semibold text-rose-700"><x-money :amount="$penalty->sisa_denda" /></td>
                    <td class="hidden px-5 py-3 md:table-cell">{{ \Carbon\Carbon::parse($penalty->tanggal_jatuh_tempo)->translatedFormat('d M Y') }}</td>
                    <td class="px-5 py-3 text-right"><x-action-menu :label="'Aksi denda pinjaman '.$penalty->kode_pinjaman"><a href="{{ route('portal.loans.show', ['role' => $role, 'loan' => $penalty->pinjaman_id]) }}" role="menuitem" class="block rounded-lg px-3 py-2 text-left text-sm font-medium text-slate-700 hover:bg-teal-50">Detail pinjaman dan bayar</a></x-action-menu></td>
                </tr>
            @empty
                <tr><td colspan="6" class="p-5"><x-empty-state icon="check-circle" title="Tidak ada denda tertunggak" description="Seluruh denda yang tercatat sudah dibayar atau belum ada keterlambatan." /></td></tr>
            @endforelse
            <x-slot:footer><x-server-pagination :paginator="$penalties" :query="request()->query()" /></x-slot:footer>
        </x-data-table>
    </div>
</x-layouts.portal>
