<x-layouts.portal :role="$role" title="Koreksi transaksi">
    @php($activeFilter = collect($filters)->filter(fn ($value) => filled($value))->isNotEmpty())

    <div class="mx-auto max-w-7xl space-y-6">
        <x-page-header
            icon="adjustments-horizontal"
            eyebrow="Kontrol pembukuan"
            title="Koreksi transaksi simpanan"
            description="Koreksi nominal setoran atau penarikan dengan alasan wajib. Saldo rekening dan kas akan dihitung ulang otomatis."
            :back-href="route('portal.cash.index', ['role' => $role])"
            back-label="Buku kas"
        />

        <x-data-table
            title="Transaksi yang dapat dikoreksi"
            :count="$transactions->total()"
            :sortable="false"
            :columns="['kode' => 'Kode / tanggal', 'anggota' => 'Anggota', 'jenis' => 'Jenis', 'nominal' => 'Nominal saat ini', 'saldo' => 'Saldo transaksi', 'actions' => 'Aksi']"
            :mobile-hidden="['anggota', 'saldo']"
        >
            <x-slot:filters>
                <form method="GET" class="grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-[minmax(0,1fr)_14rem_auto] lg:items-end">
                    <flux:field>
                        <flux:label>Pencarian</flux:label>
                        <flux:input type="search" name="q" :value="$filters['q'] ?? ''" placeholder="Kode, nama, atau kode anggota" />
                    </flux:field>
                    <flux:field>
                        <flux:label>Jenis transaksi</flux:label>
                        <flux:select name="jenis">
                            <option value="">Semua jenis</option>
                            <option value="setoran" @selected(($filters['jenis'] ?? '') === 'setoran')>Setoran</option>
                            <option value="penarikan" @selected(($filters['jenis'] ?? '') === 'penarikan')>Penarikan</option>
                        </flux:select>
                    </flux:field>
                    <div class="flex gap-2">
                        <flux:button type="submit" variant="primary">Terapkan</flux:button>
                        @if($activeFilter)
                            <flux:button :href="route('portal.savings.corrections.index', ['role' => $role])" variant="ghost">Reset</flux:button>
                        @endif
                    </div>
                </form>
            </x-slot:filters>

            @forelse($transactions as $transaction)
                <tr class="hover:bg-slate-50/80">
                    <td class="whitespace-nowrap px-5 py-3"><p class="font-mono text-xs font-semibold text-slate-700">{{ $transaction->kode_transaksi }}</p><p class="mt-1 text-xs text-slate-500">{{ $transaction->tanggal_transaksi?->translatedFormat('d M Y, H:i') }}</p></td>
                    <td class="hidden px-5 py-3 md:table-cell"><p class="font-medium text-slate-800">{{ $transaction->member?->nama_lengkap }}</p><p class="mt-1 font-mono text-xs text-slate-500">{{ $transaction->member?->kode_anggota }}</p></td>
                    <td class="px-5 py-3"><x-status-badge :status="$transaction->jenis_transaksi" /><p class="mt-1 text-xs text-slate-500">{{ $transaction->savingType?->nama_jenis }}</p></td>
                    <td class="whitespace-nowrap px-5 py-3 font-semibold text-slate-800"><x-money :amount="$transaction->jumlah" /></td>
                    <td class="hidden whitespace-nowrap px-5 py-3 text-slate-600 md:table-cell"><x-money :amount="$transaction->saldo_sebelum" /> → <x-money :amount="$transaction->saldo_sesudah" /></td>
                    <td class="px-5 py-3 text-right"><x-action-menu label="Aksi {{ $transaction->kode_transaksi }}"><flux:modal.trigger name="correct-savings-{{ $transaction->id }}"><button type="button" class="block w-full rounded-lg px-3 py-2 text-left text-sm font-medium text-amber-700 hover:bg-amber-50">Koreksi nominal</button></flux:modal.trigger><a href="{{ route('portal.savings.show', ['role' => $role, 'transaction' => $transaction]) }}" class="block rounded-lg px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Lihat transaksi</a></x-action-menu></td>
                </tr>
            @empty
                <tr><td colspan="6" class="p-4"><x-empty-state title="Tidak ada transaksi untuk dikoreksi" description="Ubah filter atau tunggu transaksi simpanan tersedia." icon="adjustments-horizontal" /></td></tr>
            @endforelse

            <x-slot:footer><x-server-pagination :paginator="$transactions" :query="collect(request()->query())->except(['page', 'per_page'])->all()" /></x-slot:footer>
        </x-data-table>

        @foreach($transactions as $transaction)
            <x-confirm-action-modal
                name="correct-savings-{{ $transaction->id }}"
                title="Koreksi {{ $transaction->kode_transaksi }}"
                :description="'Nominal saat ini: Rp'.number_format((float) $transaction->jumlah, 0, ',', '.').'. Perubahan akan menghitung ulang saldo rekening dan buku kas.'"
                confirm-label="Simpan koreksi"
                variant="primary"
                :action="route('portal.savings.corrections.store', ['role' => $role, 'transaction' => $transaction])"
            >
                <flux:field>
                    <flux:label>Nominal baru <x-required-mark /></flux:label>
                    <flux:input type="number" name="jumlah_baru" :value="$transaction->jumlah" min="0" step="0.01" inputmode="decimal" required />
                    <flux:error name="jumlah_baru" />
                </flux:field>
                <x-loans.note-field
                    name="keterangan_koreksi"
                    label="Alasan koreksi"
                    placeholder="Contoh: nominal salah input saat setoran"
                    :required="true"
                    :minlength="5"
                    :maxlength="500"
                />
                <flux:error name="keterangan_koreksi" />
            </x-confirm-action-modal>
        @endforeach

        <x-data-table title="Riwayat koreksi terbaru" :count="$logs->count()" :sortable="false" :columns="['waktu' => 'Waktu', 'transaksi' => 'Transaksi', 'perubahan' => 'Perubahan', 'alasan' => 'Alasan', 'petugas' => 'Admin']" :mobile-hidden="['alasan']">
            @forelse($logs as $log)
                <tr><td class="whitespace-nowrap px-5 py-3 text-slate-600">{{ \Carbon\Carbon::parse($log->created_at)->translatedFormat('d M Y, H:i') }}</td><td class="px-5 py-3"><p class="font-mono text-xs font-semibold text-slate-700">{{ $log->kode_transaksi }}</p><p class="mt-1 text-xs text-slate-500">{{ $log->anggota_nama }}</p></td><td class="whitespace-nowrap px-5 py-3 text-slate-700"><x-money :amount="$log->jumlah_lama" /> → <x-money :amount="$log->jumlah_baru" /></td><td class="hidden max-w-sm px-5 py-3 text-slate-600 md:table-cell">{{ $log->keterangan }}</td><td class="px-5 py-3 text-slate-700">{{ $log->petugas_nama }}</td></tr>
            @empty
                <tr><td colspan="5" class="p-4"><x-empty-state title="Belum ada koreksi" description="Riwayat koreksi akan muncul setelah Admin menyimpan perubahan nominal." icon="adjustments-horizontal" /></td></tr>
            @endforelse
        </x-data-table>
    </div>
</x-layouts.portal>
