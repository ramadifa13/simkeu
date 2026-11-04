@php
    $tabs = [
        ['label' => 'Jenis simpanan', 'href' => route('portal.master.index', ['role' => $role, 'tab' => 'simpanan']), 'active' => $tab === 'simpanan'],
        ['label' => 'Jenis pinjaman', 'href' => route('portal.master.index', ['role' => $role, 'tab' => 'pinjaman']), 'active' => $tab === 'pinjaman'],
        ['label' => 'Tarif tenor', 'href' => route('portal.master.index', ['role' => $role, 'tab' => 'tarif']), 'active' => $tab === 'tarif'],
        ['label' => 'Tarif denda', 'href' => route('portal.master.index', ['role' => $role, 'tab' => 'denda']), 'active' => $tab === 'denda'],
    ];
@endphp

<x-layouts.portal :role="$role" title="Master Data">
    <div class="mx-auto max-w-7xl">
        <x-page-header
            class="ui-page-header-rich"
            icon="adjustments-horizontal"
            eyebrow="Master data"
            title="Produk dan tarif koperasi."
            description="Kelola jenis simpanan, pinjaman, tarif tenor, dan denda harian. Khusus Admin; setiap perubahan diaudit."
        >
            <x-slot:actions>
                @if ($tab === 'simpanan')
                    <flux:button :href="route('portal.master.savings.create', ['role' => $role])" variant="primary" color="teal" icon="plus">Tambah simpanan</flux:button>
                @elseif ($tab === 'pinjaman')
                    <flux:button :href="route('portal.master.loans.create', ['role' => $role])" variant="primary" color="teal" icon="plus">Tambah pinjaman</flux:button>
                @elseif ($tab === 'tarif')
                    <flux:button :href="route('portal.master.loan-rates.create', ['role' => $role])" variant="primary" color="teal" icon="plus">Tambah tarif</flux:button>
                @else
                    <flux:button :href="route('portal.master.penalty-rates.create', ['role' => $role])" variant="primary" color="teal" icon="plus">Tambah denda</flux:button>
                @endif
            </x-slot:actions>
        </x-page-header>

        <x-tabs :items="$tabs" class="mt-6" />

        <div class="mt-5">
            @if ($tab === 'simpanan')
                <x-data-table
                    title="Jenis simpanan"
                    :count="$savings->count()"
                    :sortable="false"
                    :columns="['kode' => 'Kode', 'nama' => 'Nama', 'batas' => 'Min / Maks', 'bagi_hasil' => 'Bagi hasil', 'status' => 'Status', 'actions' => 'Aksi']"
                    :mobile-hidden="['batas', 'bagi_hasil']"
                >
                    @forelse ($savings as $saving)
                        <tr class="hover:bg-slate-50/80">
                            <td class="whitespace-nowrap px-5 py-4 font-mono text-xs text-slate-600">{{ $saving->kode_jenis }}</td>
                            <td class="px-5 py-4 font-semibold text-slate-900">
                                {{ $saving->nama_jenis }}
                                @if ($saving->deskripsi)
                                    <p class="mt-1 text-xs font-normal text-slate-500 line-clamp-1">{{ $saving->deskripsi }}</p>
                                @endif
                            </td>
                            <td class="hidden whitespace-nowrap px-5 py-4 text-slate-600 md:table-cell">
                                <x-money :amount="$saving->minimum" /> / <x-money :amount="$saving->maksimum" />
                            </td>
                            <td class="hidden whitespace-nowrap px-5 py-4 text-slate-600 md:table-cell">{{ number_format((float) $saving->bagi_hasil, 2, ',', '.') }}%</td>
                            <td class="whitespace-nowrap px-5 py-4"><x-status-badge :status="$saving->status" /></td>
                            <td class="px-5 py-4 text-right">
                                <x-action-menu label="Aksi {{ $saving->nama_jenis }}">
                                    <a href="{{ route('portal.master.savings.edit', ['role' => $role, 'saving' => $saving]) }}" class="block rounded-lg px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Edit</a>
                                    @unless (in_array(strtoupper($saving->kode_jenis), ['ANG', 'BONUS', 'SHU'], true))
                                        <flux:modal.trigger name="delete-saving-{{ $saving->id }}"><button type="button" class="block w-full rounded-lg px-3 py-2 text-left text-sm font-medium text-rose-700 hover:bg-rose-50">Hapus</button></flux:modal.trigger>
                                    @endunless
                                </x-action-menu>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="p-4"><x-empty-state title="Belum ada jenis simpanan" description="Tambahkan produk simpanan untuk anggota." /></td></tr>
                    @endforelse
                </x-data-table>
                @foreach ($savings as $saving)
                    @unless (in_array(strtoupper($saving->kode_jenis), ['ANG', 'BONUS', 'SHU'], true))
                        <x-confirm-action-modal :name="'delete-saving-'.$saving->id" :action="route('portal.master.savings.destroy', ['role' => $role, 'saving' => $saving])" method="DELETE" title="Hapus jenis simpanan" :description="'Hapus '.$saving->nama_jenis.' ('.$saving->kode_jenis.')?'" confirm-label="Hapus jenis simpanan" variant="danger"><p class="rounded-xl border border-rose-100 bg-rose-50 p-3 text-sm text-rose-800">Penghapusan hanya dapat dilakukan jika jenis ini belum memiliki rekening atau transaksi.</p></x-confirm-action-modal>
                    @endunless
                @endforeach
            @elseif ($tab === 'pinjaman')
                <x-data-table
                    title="Jenis pinjaman"
                    :count="$loans->count()"
                    :sortable="false"
                    :columns="['kode' => 'Kode', 'nama' => 'Nama', 'plafond' => 'Plafond', 'tenor' => 'Tenor / bunga', 'status' => 'Status', 'actions' => 'Aksi']"
                    :mobile-hidden="['plafond', 'tenor']"
                >
                    @forelse ($loans as $loan)
                        <tr class="hover:bg-slate-50/80">
                            <td class="whitespace-nowrap px-5 py-4 font-mono text-xs text-slate-600">{{ $loan->kode_jenis }}</td>
                            <td class="px-5 py-4 font-semibold text-slate-900">
                                {{ $loan->nama_jenis }}
                                <p class="mt-1 text-xs font-normal text-slate-500">
                                    {{ str_replace('_', ' ', $loan->frekuensi_diizinkan) }}{{ $loan->memerlukan_jaminan ? ' · jaminan' : '' }}
                                </p>
                            </td>
                            <td class="hidden whitespace-nowrap px-5 py-4 text-slate-600 md:table-cell"><x-money :amount="$loan->maksimal_pinjaman" /></td>
                            <td class="hidden whitespace-nowrap px-5 py-4 text-slate-600 md:table-cell">{{ $loan->tenor_max }} bln · {{ number_format((float) $loan->bunga, 2, ',', '.') }}%</td>
                            <td class="whitespace-nowrap px-5 py-4"><x-status-badge :status="$loan->status" /></td>
                            <td class="px-5 py-4 text-right">
                                <x-action-menu label="Aksi {{ $loan->nama_jenis }}">
                                    <a href="{{ route('portal.master.loans.edit', ['role' => $role, 'loan' => $loan]) }}" class="block rounded-lg px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Edit</a>
                                    <flux:modal.trigger name="delete-loan-{{ $loan->id }}"><button type="button" class="block w-full rounded-lg px-3 py-2 text-left text-sm font-medium text-rose-700 hover:bg-rose-50">Hapus</button></flux:modal.trigger>
                                </x-action-menu>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="p-4"><x-empty-state title="Belum ada jenis pinjaman" description="Tambahkan produk pinjaman terlebih dahulu." /></td></tr>
                    @endforelse
                </x-data-table>
                @foreach ($loans as $loan)
                    <x-confirm-action-modal :name="'delete-loan-'.$loan->id" :action="route('portal.master.loans.destroy', ['role' => $role, 'loan' => $loan])" method="DELETE" title="Hapus jenis pinjaman" :description="'Hapus '.$loan->nama_jenis.' ('.$loan->kode_jenis.')?'" confirm-label="Hapus jenis pinjaman" variant="danger"><p class="rounded-xl border border-rose-100 bg-rose-50 p-3 text-sm text-rose-800">Penghapusan ditolak bila jenis ini sudah dipakai pengajuan, pinjaman, atau masih memiliki tarif tenor.</p></x-confirm-action-modal>
                @endforeach
            @elseif ($tab === 'tarif')
                <x-data-table
                    title="Tarif tenor pinjaman"
                    :count="$loanRates->count()"
                    :sortable="false"
                    :columns="['produk' => 'Jenis pinjaman', 'tenor' => 'Tenor', 'bunga' => 'Bunga', 'actions' => 'Aksi']"
                >
                    @forelse ($loanRates as $rate)
                        <tr class="hover:bg-slate-50/80">
                            <td class="px-5 py-4 font-semibold text-slate-900">
                                {{ $rate->loan?->nama_jenis ?: '—' }}
                                <p class="mt-1 font-mono text-xs font-normal text-slate-500">{{ $rate->loan?->kode_jenis }}</p>
                            </td>
                            <td class="whitespace-nowrap px-5 py-4 text-slate-600">{{ $rate->tenor_bulan }} bulan</td>
                            <td class="whitespace-nowrap px-5 py-4 font-semibold text-slate-900">{{ number_format((float) $rate->bunga, 2, ',', '.') }}%</td>
                            <td class="px-5 py-4 text-right">
                                <x-action-menu label="Aksi tarif {{ $rate->id }}">
                                    <a href="{{ route('portal.master.loan-rates.edit', ['role' => $role, 'rate' => $rate]) }}" class="block rounded-lg px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Edit</a>
                                    <flux:modal.trigger name="delete-loan-rate-{{ $rate->id }}"><button type="button" class="block w-full rounded-lg px-3 py-2 text-left text-sm font-medium text-rose-700 hover:bg-rose-50">Hapus</button></flux:modal.trigger>
                                </x-action-menu>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="p-4"><x-empty-state title="Belum ada tarif tenor" description="Tambahkan tarif jika bunga berbeda menurut tenor." /></td></tr>
                    @endforelse
                </x-data-table>
                @foreach ($loanRates as $rate)
                    <x-confirm-action-modal :name="'delete-loan-rate-'.$rate->id" :action="route('portal.master.loan-rates.destroy', ['role' => $role, 'rate' => $rate])" method="DELETE" title="Hapus tarif tenor" :description="'Hapus tarif '.$rate->tenor_bulan.' bulan ini?'" confirm-label="Hapus tarif" variant="danger"><p class="rounded-xl border border-rose-100 bg-rose-50 p-3 text-sm text-rose-800">Tarif yang dihapus tidak dapat dipulihkan. Riwayat pinjaman tetap memakai nilai yang sudah tersimpan.</p></x-confirm-action-modal>
                @endforeach
            @else
                <x-data-table
                    title="Tarif denda harian"
                    :count="$penaltyRates->count()"
                    :sortable="false"
                    :columns="['batas' => 'Batas pinjaman', 'denda' => 'Denda / hari', 'actions' => 'Aksi']"
                >
                    @forelse ($penaltyRates as $rate)
                        <tr class="hover:bg-slate-50/80">
                            <td class="whitespace-nowrap px-5 py-4 font-semibold text-slate-900"><x-money :amount="$rate->jumlah_pinjaman" /></td>
                            <td class="whitespace-nowrap px-5 py-4 text-slate-700"><x-money :amount="$rate->denda_per_hari" /></td>
                            <td class="px-5 py-4 text-right">
                                <x-action-menu label="Aksi denda {{ $rate->id }}">
                                    <a href="{{ route('portal.master.penalty-rates.edit', ['role' => $role, 'rate' => $rate]) }}" class="block rounded-lg px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Edit</a>
                                    <flux:modal.trigger name="delete-penalty-rate-{{ $rate->id }}"><button type="button" class="block w-full rounded-lg px-3 py-2 text-left text-sm font-medium text-rose-700 hover:bg-rose-50">Hapus</button></flux:modal.trigger>
                                </x-action-menu>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="p-4"><x-empty-state title="Belum ada tarif denda" description="Tambahkan batas nominal dan denda hariannya." /></td></tr>
                    @endforelse
                </x-data-table>
                @foreach ($penaltyRates as $rate)
                    <x-confirm-action-modal :name="'delete-penalty-rate-'.$rate->id" :action="route('portal.master.penalty-rates.destroy', ['role' => $role, 'rate' => $rate])" method="DELETE" title="Hapus tarif denda" :description="'Hapus tarif denda untuk batas '.number_format((float) $rate->jumlah_pinjaman, 0, ',', '.').'?'" confirm-label="Hapus tarif denda" variant="danger"><p class="rounded-xl border border-rose-100 bg-rose-50 p-3 text-sm text-rose-800">Pastikan tarif ini memang tidak lagi digunakan sebagai acuan operasional.</p></x-confirm-action-modal>
                @endforeach
            @endif
        </div>
    </div>
</x-layouts.portal>
