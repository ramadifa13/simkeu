<x-layouts.portal :role="$role" title="SHU">
    <div class="mx-auto max-w-7xl space-y-6">
        <x-page-header icon="calculator" eyebrow="Laporan & pengaturan" title="Sisa Hasil Usaha" description="Kelola periode SHU, hitung kontribusi, sahkan, bayar hak anggota, dan hak SHU pinjaman.">
            <x-slot:actions>
                <flux:modal.trigger name="buat-periode"><flux:button variant="primary" icon="plus">Periode baru</flux:button></flux:modal.trigger>
            </x-slot:actions>
        </x-page-header>

        <x-data-table title="Periode SHU" :count="$periods->count()" :sortable="false" :columns="['tahun' => 'Tahun', 'status' => 'Status', 'pool' => 'Total SHU', 'actions' => 'Aksi']">
            @forelse($periods as $period)
                <tr class="hover:bg-slate-50/80">
                    <td class="px-5 py-3 font-semibold text-slate-900">{{ $period->nama_periode }}</td>
                    <td class="px-5 py-3"><x-status-badge :status="$period->status" /></td>
                    <td class="px-5 py-3 font-semibold text-emerald-700"><x-money :amount="$period->total_shu" /></td>
                    <td class="px-5 py-3 text-right"><a href="{{ route('portal.shu.show', ['role' => $role, 'period' => $period->id]) }}" class="text-sm font-semibold text-teal-700 hover:text-teal-900">Buka</a></td>
                </tr>
            @empty
                <tr><td colspan="4" class="p-4"><x-empty-state title="Belum ada periode" description="Buat periode SHU tahunan untuk memulai siklus." icon="calculator" /></td></tr>
            @endforelse
        </x-data-table>

        <section class="rounded-2xl border border-slate-200 bg-white p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-base font-semibold text-slate-900">Hak SHU pinjaman</h2>
                    <p class="mt-1 text-sm text-slate-500">Hak otomatis saat pinjaman tanpa jaminan lunas.</p>
                </div>
                <form method="POST" action="{{ route('portal.shu.sync-loan', ['role' => $role]) }}">@csrf<flux:button type="submit" variant="filled">Sinkronkan</flux:button></form>
            </div>
            <div class="mt-4 space-y-2">
                @forelse($loanRights as $row)
                    <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-100 px-3 py-2 text-sm">
                        <div>
                            <p class="font-semibold text-slate-800">{{ $row->nama_lengkap }} · {{ $row->jenis }}</p>
                            <p class="text-xs text-slate-500">{{ $row->kode_anggota }} · Rp{{ number_format((float) $row->jumlah, 0, ',', '.') }} · {{ $row->status_bayar }}</p>
                        </div>
                        @if($row->status_bayar === 'belum')
                            <flux:modal.trigger name="bayar-hak-{{ $row->id }}"><flux:button size="sm" variant="primary">Bayar</flux:button></flux:modal.trigger>
                        @endif
                    </div>
                    @if($row->status_bayar === 'belum')
                        <x-confirm-action-modal name="bayar-hak-{{ $row->id }}" title="Bayar hak SHU pinjaman?" description="{{ $row->nama_lengkap }} · Rp{{ number_format((float) $row->jumlah, 0, ',', '.') }}" confirm-label="Bayar" :action="route('portal.shu.pay-loan', ['role' => $role])">
                            <input type="hidden" name="hak_id" value="{{ $row->id }}" />
                            <flux:field><flux:label>Metode</flux:label><flux:select name="metode" required><option value="cash">Cash</option><option value="transfer">Transfer</option></flux:select></flux:field>
                            <flux:field><flux:label>Referensi</flux:label><flux:input name="referensi" required minlength="3" /></flux:field>
                            <x-image-upload name="bukti" label="Bukti" required />
                        </x-confirm-action-modal>
                    @endif
                @empty
                    <p class="text-sm text-slate-500">Belum ada hak SHU pinjaman.</p>
                @endforelse
            </div>
        </section>
    </div>

    <x-confirm-action-modal name="buat-periode" title="Buat periode SHU" description="Persentase alokasi wajib tepat 100%." confirm-label="Simpan periode" :action="route('portal.shu.process', ['role' => $role])" max-width="2xl">
        <input type="hidden" name="aksi" value="buat" />
        <div class="grid gap-3 sm:grid-cols-2">
            <flux:field><flux:label>Tahun</flux:label><flux:input type="number" name="tahun" :value="now()->year" min="2000" :max="now()->year" required /></flux:field>
            <flux:field><flux:label>Total SHU (Rp)</flux:label><flux:input type="number" name="total_shu" min="1" step="1" required /></flux:field>
            <flux:field class="sm:col-span-2"><flux:label>Dasar jasa simpanan</flux:label><flux:select name="basis_simpanan" required><option value="rata_harian">Rata-rata harian</option><option value="saldo_akhir">Saldo akhir</option></flux:select></flux:field>
            @foreach($categories as $key => $label)
                <flux:field><flux:label>{{ $label }} (%)</flux:label><flux:input type="number" name="persen_{{ $key }}" step="0.01" min="0" max="100" value="{{ $key === 'simpanan' ? 40 : ($key === 'pinjaman' ? 30 : ($key === 'transaksi' ? 10 : ($key === 'cadangan' ? 20 : 0))) }}" required /></flux:field>
            @endforeach
        </div>
    </x-confirm-action-modal>
</x-layouts.portal>
