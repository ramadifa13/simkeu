<x-layouts.portal :role="$role" title="Buku simpanan">
    @php($totalBalance = $balances->sum('saldo'))
    <div class="mx-auto max-w-7xl space-y-6">
        <x-page-header
            icon="book-open"
            eyebrow="{{ $personal ? 'Keuangan saya' : $member->kode_anggota }}"
            :title="$personal ? 'Buku simpanan saya' : 'Buku simpanan '.$member->nama_lengkap"
            :description="$personal ? 'Saldo per jenis dan riwayat mutasi simpanan pribadi.' : 'Saldo dan mutasi simpanan anggota dalam ledger baca-saja.'"
            :back-href="$personal ? route('portal.dashboard', ['role' => $role]) : route('portal.savings.index', ['role' => $role])"
            :back-label="$personal ? 'Dashboard' : 'Mutasi simpanan'"
        >
            <x-slot:actions>
                <button type="button" onclick="window.print()" class="inline-flex min-h-10 items-center justify-center gap-2 rounded-xl border border-white/70 bg-white px-3.5 text-sm font-semibold text-teal-800 shadow-sm transition hover:bg-teal-50"><flux:icon name="printer" class="size-4" />Cetak</button>
            </x-slot:actions>
        </x-page-header>

        <section class="ui-panel p-4 sm:p-5 print:shadow-none">
            <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-center"><div><p class="text-xs font-semibold uppercase tracking-[.14em] text-teal-700">Pemilik rekening</p><h2 class="mt-1 text-lg font-semibold text-slate-900">{{ $member->nama_lengkap }}</h2><p class="mt-1 text-sm text-slate-500">{{ $member->kode_anggota }}@if($member->telepon) · {{ $member->telepon }}@endif</p></div><div class="rounded-xl bg-teal-50 px-4 py-3 text-right"><p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Total saldo</p><p class="mt-1 text-lg font-bold text-teal-900"><x-money :amount="$totalBalance" /></p></div></div>
            <div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">@forelse($balances as $balance)<div class="rounded-xl border border-slate-100 bg-slate-50/70 p-3"><p class="font-mono text-xs font-semibold text-teal-700">{{ $balance->kode_jenis }}</p><p class="mt-1 text-sm font-medium text-slate-700">{{ $balance->nama_jenis }}</p><p class="mt-2 font-semibold text-slate-900"><x-money :amount="$balance->saldo" /></p></div>@empty <p class="text-sm text-slate-500">Belum ada jenis simpanan aktif.</p>@endforelse</div>
        </section>

        <div class="grid gap-4 sm:grid-cols-3"><x-stat-card label="Total setoran" :value="'Rp'.number_format((float) ($totals->setoran ?? 0), 0, ',', '.')" hint="Sesuai periode filter" tone="teal" icon="arrow-down-left" /><x-stat-card label="Total penarikan" :value="'Rp'.number_format((float) ($totals->penarikan ?? 0), 0, ',', '.')" hint="Sesuai periode filter" tone="amber" icon="arrow-up-right" /><x-stat-card label="Jumlah mutasi" :value="(string) ($totals->transaksi ?? 0)" hint="Sesuai periode filter" tone="sky" icon="arrows-right-left" /></div>

        <x-data-table title="Riwayat mutasi" :count="$transactions->total()" :sortable="false" :columns="['tanggal' => 'Tanggal', 'kode' => 'Kode', 'jenis' => 'Jenis simpanan', 'mutasi' => 'Mutasi', 'jumlah' => 'Jumlah', 'saldo' => 'Saldo sesudah']" :mobile-hidden="['jenis', 'kode']">
            <x-slot:filters>
                <form method="GET" class="grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4 lg:items-end">
                    <flux:field><flux:label>Jenis simpanan</flux:label><flux:select name="jenis"><option value="">Semua jenis</option>@foreach($types as $type)<option value="{{ $type->id }}" @selected((string) ($filters['jenis'] ?? '') === (string) $type->id)>{{ $type->nama_jenis }}</option>@endforeach</flux:select></flux:field>
                    <flux:field><flux:label>Dari tanggal</flux:label><flux:input type="date" name="tanggal_dari" :value="$filters['tanggal_dari'] ?? ''" /></flux:field>
                    <flux:field><flux:label>Sampai tanggal</flux:label><flux:input type="date" name="tanggal_sampai" :value="$filters['tanggal_sampai'] ?? ''" /></flux:field>
                    <div class="flex gap-2"><flux:button type="submit" variant="primary" class="flex-1">Terapkan</flux:button>@if(collect($filters)->filter(fn ($value) => filled($value))->isNotEmpty())<flux:button :href="$personal ? route('portal.savings.mine', ['role' => $role]) : route('portal.savings.book', ['role' => $role, 'member' => $member])" variant="ghost">Reset</flux:button>@endif</div>
                </form>
            </x-slot:filters>
            @forelse($transactions as $transaction)<tr class="hover:bg-slate-50/80"><td class="whitespace-nowrap px-5 py-3 text-slate-600">{{ $transaction->tanggal_transaksi?->translatedFormat('d M Y, H:i') ?? '—' }}</td><td class="hidden px-5 py-3 font-mono text-xs font-semibold text-slate-600 md:table-cell">{{ $transaction->kode_transaksi }}</td><td class="hidden px-5 py-3 text-slate-600 md:table-cell">{{ $transaction->savingType?->nama_jenis }}</td><td class="px-5 py-3"><x-status-badge :status="$transaction->jenis_transaksi" /></td><td class="whitespace-nowrap px-5 py-3 font-semibold {{ $transaction->jenis_transaksi === 'setoran' ? 'text-emerald-700' : 'text-rose-700' }}">{{ $transaction->jenis_transaksi === 'setoran' ? '+' : '−' }}<x-money :amount="$transaction->jumlah" /></td><td class="whitespace-nowrap px-5 py-3 font-semibold text-slate-800"><x-money :amount="$transaction->saldo_sesudah" /></td></tr>@empty<tr><td colspan="6" class="p-4"><x-empty-state title="Belum ada mutasi pada periode ini" description="Ubah filter periode atau jenis simpanan untuk melihat data lain." icon="book-open" /></td></tr>@endforelse
            <x-slot:footer><x-server-pagination :paginator="$transactions" :query="collect(request()->query())->except(['page', 'per_page'])->all()" /></x-slot:footer>
        </x-data-table>
    </div>
</x-layouts.portal>
