<x-layouts.portal :role="$role" title="Layanan pinjaman">
    @php($activeFilter = collect($filters)->except('per_page')->filter(fn ($value) => filled($value))->isNotEmpty())
    <div class="mx-auto max-w-7xl space-y-6">
        <x-page-header icon="lifebuoy" eyebrow="Pinjaman" title="Layanan pinjaman" description="Kelola macet, pemutihan, prioritas, lelang, tunda cair, dan tindak lanjut nasabah." />

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-stat-card label="Laporan macet" :value="(string) $queues['macet']" hint="Menunggu Admin" tone="rose" icon="exclamation-triangle" />
            <x-stat-card label="Hapus denda" :value="(string) $queues['hapus_denda']" hint="Pengajuan pemutihan" tone="amber" icon="receipt-percent" />
            <x-stat-card label="Prioritas" :value="(string) $queues['prioritas']" hint="Menunggu / pengecekan" tone="cyan" icon="star" />
            <x-stat-card label="Tunda cair" :value="(string) $queues['tunda_cair']" hint="Menunggu keputusan" tone="sky" icon="pause-circle" />
        </div>

        @if($delayRequests->isNotEmpty())
            <section class="rounded-2xl border border-slate-200 bg-white p-5">
                <h2 class="text-base font-semibold text-slate-900">Antrian tunda cair</h2>
                <p class="mt-1 text-sm text-slate-500">Persetujuan TC membatalkan pencairan; penolakan membuka kembali pengajuan.</p>
                <div class="mt-4 space-y-3">
                    @foreach($delayRequests as $item)
                        <div class="rounded-xl border border-slate-100 p-4">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <p class="font-semibold text-slate-900">{{ $item->nama_lengkap }} <span class="font-mono text-xs text-slate-500">{{ $item->kode_anggota }}</span></p>
                                    <p class="mt-1 font-mono text-xs text-slate-600">{{ $item->kode_pengajuan }}</p>
                                    <p class="mt-2 text-sm text-slate-600">{{ $item->alasan }}</p>
                                </div>
                                @can('decide-layanan')
                                    <div class="flex gap-2">
                                        <flux:modal.trigger name="tc-approve-{{ $item->id }}"><flux:button size="sm" variant="primary">Setujui</flux:button></flux:modal.trigger>
                                        <flux:modal.trigger name="tc-reject-{{ $item->id }}"><flux:button size="sm" variant="danger">Tolak</flux:button></flux:modal.trigger>
                                    </div>
                                @endcan
                            </div>
                        </div>
                        @can('decide-layanan')
                            <x-confirm-action-modal name="tc-approve-{{ $item->id }}" title="Setujui tunda cair?" description="Pengajuan akan ditandai batal cair." confirm-label="Setujui TC" :action="route('portal.layanan.tunda-cair.decide', ['role' => $role])">
                                <input type="hidden" name="tc_id" value="{{ $item->id }}" />
                                <input type="hidden" name="keputusan" value="disetujui" />
                                <flux:field><flux:label>Catatan Admin</flux:label><flux:textarea name="catatan" rows="3" required minlength="5" /></flux:field>
                            </x-confirm-action-modal>
                            <x-confirm-action-modal name="tc-reject-{{ $item->id }}" title="Tolak tunda cair?" description="Pengajuan kembali dapat dicairkan." confirm-label="Tolak TC" variant="danger" :action="route('portal.layanan.tunda-cair.decide', ['role' => $role])">
                                <input type="hidden" name="tc_id" value="{{ $item->id }}" />
                                <input type="hidden" name="keputusan" value="ditolak" />
                                <flux:field><flux:label>Catatan Admin</flux:label><flux:textarea name="catatan" rows="3" required minlength="5" /></flux:field>
                            </x-confirm-action-modal>
                        @endcan
                    @endforeach
                </div>
            </section>
        @endif

        <x-data-table title="Daftar pinjaman" :count="$loans->total()" :sortable="false" :columns="['kode' => 'Kode', 'anggota' => 'Anggota', 'jenis' => 'Jenis', 'status' => 'Status', 'sisa' => 'Sisa pokok', 'actions' => 'Aksi']" :mobile-hidden="['jenis']">
            <x-slot:filters>
                <form method="GET" class="grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4 lg:items-end">
                    <flux:field class="lg:col-span-2"><flux:label>Pencarian</flux:label><flux:input type="search" name="q" :value="$filters['q'] ?? ''" placeholder="Kode pinjaman atau anggota" /></flux:field>
                    <flux:field><flux:label>Status</flux:label><flux:select name="status"><option value="">Semua</option><option value="aktif" @selected(($filters['status'] ?? '') === 'aktif')>Aktif</option><option value="lunas" @selected(($filters['status'] ?? '') === 'lunas')>Lunas</option><option value="macet" @selected(($filters['status'] ?? '') === 'macet')>Terlambat</option></flux:select></flux:field>
                    <div class="flex gap-2"><flux:button type="submit" variant="primary" class="flex-1">Terapkan</flux:button>@if($activeFilter)<flux:button :href="route('portal.layanan.index', ['role' => $role])" variant="ghost">Reset</flux:button>@endif</div>
                </form>
            </x-slot:filters>
            @forelse($loans as $row)
                <tr class="hover:bg-slate-50/80">
                    <td class="whitespace-nowrap px-5 py-3 font-mono text-xs font-semibold text-slate-700">{{ $row->kode_pinjaman }}</td>
                    <td class="px-5 py-3 font-semibold text-slate-900">{{ $row->nama_lengkap }}<p class="mt-1 font-mono text-xs font-normal text-slate-500">{{ $row->kode_anggota }}</p></td>
                    <td class="hidden px-5 py-3 text-slate-600 md:table-cell">{{ $row->nama_jenis }}</td>
                    <td class="px-5 py-3"><x-status-badge :status="$row->status" /></td>
                    <td class="whitespace-nowrap px-5 py-3 font-semibold text-slate-800"><x-money :amount="$row->sisa_pokok" /></td>
                    <td class="px-5 py-3 text-right"><a href="{{ route('portal.layanan.show', ['role' => $role, 'loan' => $row->id]) }}" class="text-sm font-semibold text-teal-700 hover:text-teal-900">Kelola</a></td>
                </tr>
            @empty
                <tr><td colspan="6" class="p-4"><x-empty-state title="Belum ada pinjaman" description="Cari pinjaman aktif atau lunas untuk memulai layanan." icon="lifebuoy" /></td></tr>
            @endforelse
            <x-slot:footer><x-server-pagination :paginator="$loans" :query="collect(request()->query())->except(['page', 'per_page'])->all()" /></x-slot:footer>
        </x-data-table>
    </div>
</x-layouts.portal>
