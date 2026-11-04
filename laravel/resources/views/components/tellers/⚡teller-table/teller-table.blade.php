<div class="space-y-5">
    {{-- Tanpa title strip: page-header sudah cukup, hindari strip ganda heading+filter. --}}
    <x-data-table
        class="ui-teller-table"
        :columns="['username' => 'Username', 'nama_lengkap' => 'Nama lengkap', 'telepon' => 'Kontak', 'status' => 'Status', 'last_login' => 'Terakhir masuk', 'actions' => 'Aksi']"
        :mobile-hidden="['telepon', 'last_login']"
    >
        <x-slot:footer>
            @if ($this->tellers->total() > 0)
                <x-pagination :paginator="$this->tellers" per-page-model="perPage" />
            @endif
        </x-slot:footer>
        <x-slot:filters>
            <x-table-filters class="ui-table-filter-bar">
                <div class="ui-filter-field">
                    <p class="ui-filter-label">Pencarian</p>
                    <flux:input :loading="false" wire:model.live.debounce.300ms="search" type="search" placeholder="Cari nama, username, atau kontak" aria-label="Cari teller" />
                </div>
                <div class="ui-filter-field">
                    <p class="ui-filter-label">Status akun</p>
                    <flux:select wire:model.live="status" aria-label="Filter status teller">
                        <option value="">Semua status</option>
                        <option value="aktif">Aktif</option>
                        <option value="nonaktif">Nonaktif</option>
                    </flux:select>
                </div>
                <div class="flex flex-wrap items-end gap-2">
                    <span class="ui-table-count mb-0.5">{{ $this->tellers->total() }} akun</span>
                    @if ($search !== '' || $status !== '')
                        <flux:button type="button" variant="ghost" wire:click="resetFilters" class="ui-table-reset">Reset</flux:button>
                    @endif
                </div>
            </x-table-filters>
        </x-slot:filters>

        @forelse ($this->tellers as $teller)
            <tr wire:key="teller-{{ $teller->id }}" wire:loading.remove wire:target="search,status,sort,resetFilters" class="hover:bg-slate-50/80">
                <td class="whitespace-nowrap px-5 py-4 font-mono text-xs text-slate-600">{{ $teller->username }}</td>
                <td class="px-5 py-4 font-semibold text-slate-900">
                    {{ $teller->nama_lengkap }}
                    <p class="mt-1 text-xs font-normal text-slate-500 md:hidden">{{ $teller->username }} · {{ $teller->telepon ?: ($teller->email ?: '—') }}</p>
                </td>
                <td class="hidden whitespace-nowrap px-5 py-4 text-slate-600 md:table-cell">
                    <p>{{ $teller->telepon ?: '—' }}</p>
                    <p class="mt-0.5 text-xs text-slate-500">{{ $teller->email ?: '—' }}</p>
                </td>
                <td class="whitespace-nowrap px-5 py-4"><x-status-badge :status="$teller->status" /></td>
                <td class="hidden whitespace-nowrap px-5 py-4 text-slate-600 md:table-cell">
                    {{ $teller->last_login?->translatedFormat('d M Y H:i') ?: 'Belum pernah' }}
                </td>
                <td class="px-5 py-4 text-right">
                    <x-action-menu label="Aksi untuk {{ $teller->nama_lengkap }}">
                        <a href="{{ route('portal.tellers.edit', ['role' => $role, 'teller' => $teller]) }}" class="block rounded-lg px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Edit</a>
                        <flux:modal.trigger name="reset-teller-{{ $teller->id }}">
                            <button type="button" class="block w-full rounded-lg px-3 py-2 text-left text-sm font-medium text-slate-700 hover:bg-teal-50">Reset password</button>
                        </flux:modal.trigger>
                        <flux:modal.trigger name="delete-teller-{{ $teller->id }}">
                            <button type="button" class="block w-full rounded-lg px-3 py-2 text-left text-sm font-medium text-rose-700 hover:bg-rose-50">Hapus</button>
                        </flux:modal.trigger>
                    </x-action-menu>
                </td>
            </tr>
        @empty
            <tr wire:loading.remove wire:target="search,status,sort,resetFilters">
                <td colspan="6" class="p-4">
                    <x-empty-state title="Data teller tidak ditemukan" description="Tambah akun teller baru atau ubah filter pencarian." />
                </td>
            </tr>
        @endforelse

        @for ($skeleton = 0; $skeleton < 5; $skeleton++)
            <tr wire:loading wire:target="search,status,sort,resetFilters" class="ui-table-skeleton-row">
                <td><div class="ui-table-skeleton w-20"></div></td>
                <td><div class="ui-table-skeleton w-40"></div></td>
                <td class="hidden md:table-cell"><div class="ui-table-skeleton w-28"></div></td>
                <td><div class="ui-table-skeleton w-16"></div></td>
                <td class="hidden md:table-cell"><div class="ui-table-skeleton w-28"></div></td>
                <td><div class="ui-table-skeleton ml-auto w-8"></div></td>
            </tr>
        @endfor
    </x-data-table>

    @foreach ($this->tellers as $teller)
        <x-confirm-action-modal
            :name="'reset-teller-'.$teller->id"
            :action="route('portal.tellers.password.reset', ['role' => $role, 'teller' => $teller])"
            title="Reset password teller"
            :description="'Password login '.$teller->username.' akan diganti sandi acak sekali tampil.'"
            confirm-label="Ya, reset password"
        >
            <p class="text-sm text-slate-600">Sampaikan password awal ini hanya kepada teller yang bersangkutan.</p>
        </x-confirm-action-modal>
        <x-confirm-action-modal
            :name="'delete-teller-'.$teller->id"
            :action="route('portal.tellers.destroy', ['role' => $role, 'teller' => $teller])"
            method="DELETE"
            title="Hapus teller"
            :description="'Anda akan menghapus akun '.$teller->nama_lengkap.' ('.$teller->username.').'"
            confirm-label="Hapus teller"
            variant="danger"
        >
            <div class="space-y-2 rounded-xl border border-rose-100 bg-rose-50 px-3 py-3 text-sm text-rose-800">
                <p class="font-semibold">Catatan penghapusan</p>
                <ul class="list-disc space-y-1 pl-5">
                    <li>Teller aktif terakhir tidak dapat dihapus</li>
                    <li>Status saat ini: <strong>{{ ucfirst($teller->status) }}</strong></li>
                </ul>
                <p class="text-xs leading-5 text-rose-700">Jika gagal, toast akan menjelaskan penyebabnya (misalnya nonaktifkan dulu atau buat teller lain).</p>
            </div>
        </x-confirm-action-modal>
    @endforeach
</div>
