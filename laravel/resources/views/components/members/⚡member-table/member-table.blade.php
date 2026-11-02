<div class="space-y-5">
    <x-loading-state wire:loading wire:target="search,status,sort,resetFilters" />
    <x-data-table title="Daftar anggota" :count="$this->members->total()" :columns="['kode_anggota' => 'Kode', 'nama_lengkap' => 'Nama anggota', 'telepon' => 'Telepon', 'status' => 'Status', 'tanggal_daftar' => 'Terdaftar', 'actions' => 'Aksi']" :mobile-hidden="['telepon', 'tanggal_daftar']">
        <x-slot:columnFilters>
            <th class="ui-table-filter-cell ui-table-filter-muted"><span aria-hidden="true">&mdash;</span><span class="sr-only">Tidak ada filter kode</span></th>
            <th class="ui-table-filter-cell"><flux:input wire:model.live.debounce.300ms="search" type="search" placeholder="Cari nama, kode, telepon" aria-label="Cari anggota" /></th>
            <th class="ui-table-filter-cell ui-table-filter-muted"><span aria-hidden="true">&mdash;</span><span class="sr-only">Tidak ada filter telepon</span></th>
            <th class="ui-table-filter-cell"><flux:select wire:model.live="status" aria-label="Filter status anggota"><option value="">Semua</option><option value="aktif">Aktif</option><option value="nonaktif">Nonaktif</option></flux:select></th>
            <th class="ui-table-filter-cell ui-table-filter-muted"><span aria-hidden="true">&mdash;</span><span class="sr-only">Tidak ada filter tanggal</span></th>
            <th class="ui-table-filter-cell text-right"><flux:button type="button" variant="ghost" wire:click="resetFilters" class="ui-table-reset">Reset</flux:button></th>
        </x-slot:columnFilters>
        @forelse ($this->members as $member)
            <tr wire:key="member-{{ $member->id }}" class="hover:bg-slate-50/80">
                <td class="whitespace-nowrap px-5 py-4 font-mono text-xs text-slate-600">{{ $member->kode_anggota }}</td>
                <td class="px-5 py-4 font-semibold text-slate-900">{{ $member->nama_lengkap }}<p class="mt-1 text-xs font-normal text-slate-500 md:hidden">{{ $member->telepon ?: '—' }} · {{ $member->tanggal_daftar?->translatedFormat('d M Y') ?: '—' }}</p></td>
                <td class="hidden whitespace-nowrap px-5 py-4 text-slate-600 md:table-cell">{{ $member->telepon ?: '—' }}</td>
                <td class="whitespace-nowrap px-5 py-4"><x-status-badge :status="$member->status" /></td>
                <td class="hidden whitespace-nowrap px-5 py-4 text-slate-600 md:table-cell">{{ $member->tanggal_daftar?->translatedFormat('d M Y') ?: '—' }}</td>
                <td class="px-5 py-4 text-right"><x-action-menu label="Aksi untuk {{ $member->nama_lengkap }}"><a href="{{ route('portal.members.show', ['role' => $role, 'member' => $member]) }}" class="block rounded-lg px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Lihat detail</a></x-action-menu></td>
            </tr>
        @empty
            <tr><td colspan="6" class="px-5 py-10 text-center text-sm text-slate-500">Data anggota tidak ditemukan.</td></tr>
        @endforelse
    </x-data-table>

    <x-pagination :paginator="$this->members" per-page-model="perPage" />
</div>
