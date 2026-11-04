<x-layouts.portal :role="$role" title="Rekonsiliasi kas">
    <div class="mx-auto max-w-6xl space-y-6">
        <x-page-header
            icon="shield-check"
            eyebrow="Kontrol pembukuan"
            title="Rekonsiliasi buku kas"
            description="Bandingkan saldo tersimpan dengan saldo yang dihitung ulang dari seluruh mutasi kas."
            :back-href="route('portal.cash.index', ['role' => $role])"
            back-label="Buku kas"
        >
            <x-slot:actions>
                @if($totals['mismatches']->isNotEmpty())
                    <flux:modal.trigger name="rebuild-cash-ledger"><flux:button type="button" variant="primary" icon="arrow-path">Sinkronkan saldo</flux:button></flux:modal.trigger>
                @endif
            </x-slot:actions>
        </x-page-header>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-stat-card label="Mutasi diperiksa" :value="(string) $totals['entries']" hint="Urut tanggal dan ID" tone="sky" icon="receipt-percent" />
            <x-stat-card label="Kas masuk" :value="'Rp'.number_format($totals['masuk'], 0, ',', '.')" hint="Akumulasi ledger" tone="teal" icon="arrow-down-left" />
            <x-stat-card label="Kas keluar" :value="'Rp'.number_format($totals['keluar'], 0, ',', '.')" hint="Akumulasi ledger" tone="rose" icon="arrow-up-right" />
            <x-stat-card label="Saldo hitung ulang" :value="'Rp'.number_format($totals['saldo'], 0, ',', '.')" :hint="$totals['mismatches']->isEmpty() ? 'Ledger konsisten' : $totals['mismatches']->count().' baris perlu sinkronisasi'" :tone="$totals['mismatches']->isEmpty() ? 'teal' : 'amber'" icon="shield-check" />
        </div>

        @if($totals['mismatches']->isEmpty())
            <x-empty-state title="Buku kas sudah konsisten" description="Saldo sebelum dan sesudah setiap mutasi sesuai dengan perhitungan ulang ledger." icon="shield-check" />
        @else
            <x-data-table title="Ketidaksesuaian saldo" :count="$totals['mismatches']->count()" :sortable="false" :columns="['tanggal' => 'Tanggal', 'keterangan' => 'Keterangan', 'tersimpan' => 'Saldo tersimpan', 'seharusnya' => 'Saldo seharusnya']" :mobile-hidden="[]">
                @foreach($totals['mismatches'] as $mismatch)
                    <tr class="hover:bg-slate-50/80"><td class="whitespace-nowrap px-5 py-3 text-slate-600">{{ \Carbon\Carbon::parse($mismatch->tanggal)->translatedFormat('d M Y, H:i') }}</td><td class="px-5 py-3 text-slate-700">{{ $mismatch->keterangan ?: 'Mutasi kas #'.$mismatch->id }}</td><td class="whitespace-nowrap px-5 py-3 text-rose-700"><x-money :amount="$mismatch->stored_before" /> → <x-money :amount="$mismatch->stored_after" /></td><td class="whitespace-nowrap px-5 py-3 font-semibold text-emerald-700"><x-money :amount="$mismatch->expected_before" /> → <x-money :amount="$mismatch->expected_after" /></td></tr>
                @endforeach
            </x-data-table>
        @endif
    </div>

    <x-confirm-action-modal name="rebuild-cash-ledger" title="Sinkronkan saldo buku kas?" description="Saldo sebelum dan sesudah seluruh mutasi kas akan dihitung ulang dari urutan tanggal. Nominal transaksi tidak diubah." confirm-label="Sinkronkan sekarang" variant="primary" :action="route('portal.cash.reconciliation.rebuild', ['role' => $role])">
        <flux:field><flux:label>Alasan sinkronisasi <x-required-mark /></flux:label><flux:textarea name="alasan" rows="3" minlength="5" maxlength="500" required placeholder="Contoh: sinkronisasi setelah koreksi mutasi" /><flux:error name="alasan" /></flux:field>
    </x-confirm-action-modal>
</x-layouts.portal>
