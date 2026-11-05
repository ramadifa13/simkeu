<x-layouts.portal :role="$role" title="Pinjaman">
    <div class="mx-auto max-w-7xl space-y-6">
        <x-page-header icon="banknotes" eyebrow="Pinjaman" title="{{ $role === 'anggota' ? 'Pinjaman saya' : 'Daftar pinjaman' }}" description="Pantau status pinjaman, sisa pokok, dan jadwal pembayaran.">
            @if ($role !== 'anggota')
                <x-slot:actions><div class="flex gap-2"><flux:button :href="route('portal.loans.cart', ['role' => $role])" variant="ghost" icon="shopping-cart">Keranjang</flux:button><flux:button :href="route('portal.loans.applications.index', ['role' => $role])" variant="primary" icon="document-plus">Pengajuan pinjaman</flux:button></div></x-slot:actions>
            @endif
        </x-page-header>
        <x-loans.workflow :role="$role" :active="request('focus') === 'denda' ? 'penalties' : (request('focus') === 'pelunasan' ? 'settlement' : 'installments')" />

        <x-data-table title="Daftar pinjaman" icon="banknotes" :count="$loans->total()" :sortable="false" :columns="['kode' => 'Kode', 'anggota' => 'Anggota', 'produk' => 'Produk', 'pokok' => 'Sisa pokok', 'status' => 'Status', 'actions' => 'Aksi']" :mobile-hidden="['produk', 'pokok']">
            <x-slot:filters>
                <form method="GET" class="grid gap-3 border-b border-slate-100 bg-slate-50/70 p-4 sm:grid-cols-2 lg:grid-cols-[minmax(14rem,1.2fr)_minmax(9rem,.7fr)_minmax(11rem,.8fr)_auto] lg:items-end">
                    <flux:field><flux:label>Pencarian</flux:label><flux:input type="search" name="q" :value="$filters['q'] ?? ''" placeholder="Kode pinjaman atau nama anggota" /></flux:field>
                    <flux:field><flux:label>Status</flux:label><flux:select name="status"><option value="">Semua status</option>@foreach (['aktif', 'lunas', 'macet'] as $status)<option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ ucfirst($status) }}</option>@endforeach</flux:select></flux:field>
                    <flux:field><flux:label>Hari angsuran</flux:label><flux:select name="hari"><option value="">Semua hari</option>@foreach ([1=>'Senin',2=>'Selasa',3=>'Rabu',4=>'Kamis',5=>'Jumat',6=>'Sabtu',7=>'Minggu'] as $day => $label)<option value="{{ $day }}" @selected((string)($filters['hari'] ?? '') === (string)$day)>{{ $label }}</option>@endforeach</flux:select></flux:field>
                    <div class="flex gap-2"><flux:button type="submit" variant="primary">Terapkan</flux:button>@if(request()->filled('q') || request()->filled('status') || request()->filled('hari'))<flux:button :href="route('portal.loans.index', ['role'=>$role])" variant="ghost">Reset</flux:button>@endif</div>
                </form>
            </x-slot:filters>
            @forelse ($loans as $loan)
                <tr>
                    <td class="px-5 py-3 font-mono text-xs">{{ $loan->kode_pinjaman }}</td>
                    <td class="px-5 py-3">
                        <div class="flex items-center gap-3">
                            @if ($loan->avatar_url ?? null)
                                <img src="{{ $loan->avatar_url }}" alt="" class="size-9 shrink-0 rounded-full object-cover ring-1 ring-slate-200" />
                            @else
                                <span class="grid size-9 shrink-0 place-items-center rounded-full bg-teal-100 text-xs font-bold text-teal-800">{{ mb_substr($loan->nama_lengkap, 0, 1) }}</span>
                            @endif
                            <div class="min-w-0"><span class="font-semibold">{{ $loan->nama_lengkap }}</span><span class="block text-xs text-slate-500">{{ $loan->kode_anggota }}</span></div>
                        </div>
                    </td>
                    <td class="hidden px-5 py-3 md:table-cell">{{ $loan->nama_jenis }}<span class="block text-xs text-slate-500">{{ $loan->tenor }} bulan · {{ str_replace('_', ' ', $loan->frekuensi) }}</span></td>
                    <td class="hidden px-5 py-3 font-semibold md:table-cell"><x-money :amount="$loan->sisa_pokok" /></td>
                    <td class="px-5 py-3"><x-status-badge :status="$loan->status" /></td>
                    <td class="px-5 py-3 text-right"><x-action-menu :label="'Aksi pinjaman '.$loan->kode_pinjaman"><a href="{{ route('portal.loans.show', ['role' => $role, 'loan' => $loan->id]) }}" role="menuitem" class="block rounded-lg px-3 py-2 text-left text-sm font-medium text-slate-700 hover:bg-teal-50">Lihat detail pinjaman</a></x-action-menu></td>
                </tr>
            @empty
                <tr><td colspan="6" class="p-4"><x-empty-state title="Belum ada pinjaman" description="Data pinjaman akan tampil setelah pengajuan dicairkan." icon="banknotes" /></td></tr>
            @endforelse
            <x-slot:footer><x-server-pagination :paginator="$loans" :query="collect(request()->query())->except(['page', 'per_page'])->all()" /></x-slot:footer>
        </x-data-table>
    </div>
</x-layouts.portal>
