<div class="space-y-5">
    <x-data-table class="ui-member-table" title="Daftar anggota" :count="$this->members->total()" :columns="['kode_anggota' => 'Kode', 'nama_lengkap' => 'Nama anggota', 'telepon' => 'Telepon', 'status' => 'Status', 'tanggal_daftar' => 'Terdaftar', 'actions' => 'Aksi']" :mobile-hidden="['telepon', 'tanggal_daftar']">
        <x-slot:footer>@if ($this->members->total() > 0)<x-pagination :paginator="$this->members" per-page-model="perPage" />@endif</x-slot:footer>
        <x-slot:filters>
            <x-table-filters class="ui-table-filter-bar">
                <div class="ui-filter-field"><p class="ui-filter-label">Pencarian</p><flux:input :loading="false" wire:model.live.debounce.300ms="search" type="search" placeholder="Cari nama, kode, atau telepon" aria-label="Cari anggota" /></div>
                <div class="ui-filter-field"><p class="ui-filter-label">Status anggota</p><flux:select wire:model.live="status" aria-label="Filter status anggota"><option value="">Semua status</option><option value="aktif">Aktif</option><option value="nonaktif">Nonaktif</option></flux:select></div>
                @if ($search !== '' || $status !== '')<flux:button type="button" variant="ghost" wire:click="resetFilters" class="ui-table-reset">Reset</flux:button>@endif
            </x-table-filters>
        </x-slot:filters>
        @forelse ($this->members as $member)
            <tr wire:key="member-{{ $member->id }}" wire:loading.remove wire:target="search,status,sort,resetFilters" class="hover:bg-slate-50/80">
                <td class="ui-member-code-cell whitespace-nowrap px-5 py-4 font-mono text-xs text-slate-600">{{ $member->kode_anggota }}</td>
                <td class="px-5 py-4 font-semibold text-slate-900">
                    <div class="flex items-center gap-3">
                        @if ($member->avatar_url)
                            <img src="{{ $member->avatar_url }}" alt="" class="size-9 shrink-0 rounded-full object-cover ring-1 ring-slate-200" />
                        @else
                            <span class="grid size-9 shrink-0 place-items-center rounded-full bg-teal-100 text-xs font-bold text-teal-800">{{ mb_substr($member->nama_lengkap, 0, 1) }}</span>
                        @endif
                        <div class="min-w-0">
                            <p class="truncate">{{ $member->nama_lengkap }}</p>
                            <p class="mt-1 text-xs font-normal text-slate-500 md:hidden">{{ $member->kode_anggota }} · {{ $member->telepon ?: '—' }}</p>
                        </div>
                    </div>
                </td>
                <td class="hidden whitespace-nowrap px-5 py-4 text-slate-600 md:table-cell">{{ $member->telepon ?: '—' }}</td>
                <td class="whitespace-nowrap px-5 py-4"><x-status-badge :status="$member->status" /></td>
                <td class="hidden whitespace-nowrap px-5 py-4 text-slate-600 md:table-cell">{{ $member->tanggal_daftar?->translatedFormat('d M Y') ?: '—' }}</td>
                <td class="px-5 py-4 text-right"><x-action-menu label="Aksi untuk {{ $member->nama_lengkap }}"><a href="{{ route('portal.members.show', ['role' => $role, 'member' => $member]) }}" class="block rounded-lg px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Lihat detail</a><a href="{{ route('portal.members.edit', ['role' => $role, 'member' => $member]) }}" class="block rounded-lg px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Edit</a><flux:modal.trigger name="reset-member-{{ $member->id }}"><button type="button" class="block w-full rounded-lg px-3 py-2 text-left text-sm font-medium text-slate-700 hover:bg-teal-50">Reset password</button></flux:modal.trigger>@if(auth()->user()?->role === 'admin')<flux:modal.trigger name="delete-member-{{ $member->id }}"><button type="button" class="block w-full rounded-lg px-3 py-2 text-left text-sm font-medium text-rose-700 hover:bg-rose-50">Hapus</button></flux:modal.trigger>@endif</x-action-menu></td>
            </tr>
        @empty
            <tr wire:loading.remove wire:target="search,status,sort,resetFilters"><td colspan="6" class="p-4"><x-empty-state title="Data anggota tidak ditemukan" description="Coba ubah kata kunci pencarian atau status yang sedang dipilih." /></td></tr>
        @endforelse
        @for ($skeleton = 0; $skeleton < 5; $skeleton++)
            <tr wire:loading wire:target="search,status,sort,resetFilters" class="ui-table-skeleton-row"><td><div class="ui-table-skeleton w-16"></div></td><td><div class="ui-table-skeleton w-48"></div></td><td class="hidden md:table-cell"><div class="ui-table-skeleton w-28"></div></td><td><div class="ui-table-skeleton w-16"></div></td><td class="hidden md:table-cell"><div class="ui-table-skeleton w-24"></div></td><td><div class="ui-table-skeleton ml-auto w-8"></div></td></tr>
        @endfor
    </x-data-table>

    @foreach ($this->members as $member)
        <x-confirm-action-modal :name="'reset-member-'.$member->id" :action="route('portal.members.password.reset', ['role' => $role, 'member' => $member])" title="Reset password anggota" :description="'Password login '.$member->kode_anggota.' akan diganti sandi acak sekali tampil.'" confirm-label="Ya, reset password"><p class="text-sm text-slate-600">Salin sandi baru dari pesan sukses dan sampaikan hanya kepada anggota yang bersangkutan.</p></x-confirm-action-modal>
        @if(auth()->user()?->role === 'admin')
            <x-confirm-action-modal
                :name="'delete-member-'.$member->id"
                :action="route('portal.members.destroy', ['role' => $role, 'member' => $member])"
                method="DELETE"
                title="Hapus anggota"
                :description="'Anda akan menghapus '.$member->nama_lengkap.' ('.$member->kode_anggota.').'"
                confirm-label="Hapus anggota"
                variant="danger"
            >
                <div class="space-y-2 rounded-xl border border-rose-100 bg-rose-50 px-3 py-3 text-sm text-rose-800">
                    <p class="font-semibold">Syarat penghapusan</p>
                    <ul class="list-disc space-y-1 pl-5">
                        <li>Status harus <strong>Nonaktif</strong>@if($member->status === 'aktif') <span class="font-normal">(saat ini masih Aktif)</span>@endif</li>
                        <li>Tidak punya rekening simpanan, transaksi, pinjaman, pengajuan, atau hak SHU</li>
                    </ul>
                    <p class="text-xs leading-5 text-rose-700">Jika gagal, toast akan menjelaskan penyebabnya. Data keuangan tidak dihapus demi audit.</p>
                </div>
            </x-confirm-action-modal>
        @endif
    @endforeach
</div>
