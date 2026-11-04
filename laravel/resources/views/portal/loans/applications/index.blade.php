<x-layouts.portal :role="$role" title="Pengajuan pinjaman">
    <div class="mx-auto max-w-7xl space-y-5">
        <x-page-header icon="banknotes" eyebrow="Pinjaman" title="Pengajuan pinjaman" description="Pantau pengajuan, periksa detail, dan lanjutkan proses sesuai statusnya.">
            <x-slot:actions><flux:button :href="route('portal.loans.applications.create', ['role' => $role])" variant="primary" icon="plus">Tambah pengajuan</flux:button></x-slot:actions>
        </x-page-header>
        <x-loans.workflow :role="$role" :active="request('status') === 'menunggu' ? 'review' : (request('status') === 'disetujui' ? 'disbursement' : 'applications')" />
        <x-data-table title="Daftar pengajuan" icon="document-plus" :count="$applications->total()" :sortable="false" :columns="['kode' => 'Kode', 'anggota' => 'Anggota', 'produk' => 'Produk', 'nominal' => 'Nominal', 'status' => 'Status', 'actions' => 'Aksi']" :mobile-hidden="['produk', 'nominal']">
            <x-slot:filters>
                <form method="GET" class="grid gap-3 border-b border-slate-100 bg-slate-50/70 p-4 sm:grid-cols-[minmax(12rem,1.2fr)_minmax(10rem,.8fr)_auto] sm:items-end">
                    <flux:field><flux:label>Pencarian</flux:label><flux:input type="search" name="q" :value="$filters['q'] ?? ''" placeholder="Kode pengajuan atau nama anggota" /></flux:field>
                    <flux:field><flux:label>Status</flux:label><flux:select name="status"><option value="">Semua status</option>@foreach (['menunggu', 'disetujui', 'ditolak', 'dicairkan', 'batal_cair'] as $status)<option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ ucfirst(str_replace('_', ' ', $status)) }}</option>@endforeach</flux:select></flux:field>
                    <div class="flex gap-2"><flux:button type="submit" variant="primary">Terapkan</flux:button>@if(request()->filled('q') || request()->filled('status'))<flux:button :href="route('portal.loans.applications.index', ['role' => $role])" variant="ghost">Reset</flux:button>@endif</div>
                </form>
            </x-slot:filters>
            @forelse ($applications as $application)
                <tr>
                    <td class="whitespace-nowrap px-4 py-3 font-mono text-xs text-slate-600">{{ $application->kode_pengajuan }}</td>
                    <td class="px-4 py-3"><span class="font-semibold text-slate-900">{{ $application->nama_lengkap }}</span><span class="block text-xs text-slate-500">{{ $application->kode_anggota }}</span></td>
                    <td class="hidden px-4 py-3 text-slate-700 md:table-cell">{{ $application->nama_jenis }}<span class="block text-xs text-slate-500">{{ $application->tenor }} bulan · {{ str_replace('_', ' ', $application->frekuensi) }}</span></td>
                    <td class="hidden whitespace-nowrap px-4 py-3 font-medium text-slate-800 md:table-cell"><x-money :amount="$application->jumlah_pinjaman" /></td>
                    <td class="px-4 py-3"><x-status-badge :status="$application->status" /></td>
                    <td class="px-4 py-3 text-right"><x-action-menu :label="'Aksi pengajuan '.$application->kode_pengajuan"><a href="{{ route('portal.loans.applications.show', ['role' => $role, 'application' => $application->id]) }}" role="menuitem" class="block rounded-lg px-3 py-2 text-left text-sm font-medium text-slate-700 hover:bg-teal-50 hover:text-teal-900"><flux:icon name="eye" class="mr-2 inline size-4" />Lihat detail</a></x-action-menu></td>
                </tr>
            @empty
                <tr><td colspan="6" class="p-4"><x-empty-state title="Belum ada pengajuan" description="Ubah filter atau buat pengajuan baru untuk memulai proses." icon="banknotes" /></td></tr>
            @endforelse
            <x-slot:footer><x-server-pagination :paginator="$applications" :query="collect(request()->query())->except(['page', 'per_page'])->all()" /></x-slot:footer>
        </x-data-table>
    </div>
</x-layouts.portal>
