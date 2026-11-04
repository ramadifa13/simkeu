<x-layouts.portal :role="$role" title="Simpanan & kas">
    @php($activeFilter = collect($filters)->filter(fn ($value) => filled($value))->isNotEmpty())
    @php($memberOptions = $members->mapWithKeys(fn ($member) => [$member->id => $member->kode_anggota.' — '.$member->nama_lengkap])->all())
    <div class="mx-auto max-w-7xl space-y-6">
        <x-page-header
            icon="wallet"
            eyebrow="Operasional"
            title="Simpanan dan kas"
            description="Catat setoran atau penarikan, pantau saldo rekening anggota, dan tinjau mutasi kas secara aman."
        >
            <x-slot:actions>
                <flux:modal.trigger name="deposit-saving"><flux:button type="button" variant="primary" icon="plus">Setoran</flux:button></flux:modal.trigger>
                <flux:modal.trigger name="withdraw-saving"><flux:button type="button" variant="outline" icon="minus">Penarikan</flux:button></flux:modal.trigger>
            </x-slot:actions>
        </x-page-header>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-stat-card label="Setoran hari ini" :value="'Rp'.number_format($summary['setoranHariIni'], 0, ',', '.')" hint="Mutasi masuk simpanan" tone="teal" icon="arrow-down-left" />
            <x-stat-card label="Penarikan hari ini" :value="'Rp'.number_format($summary['penarikanHariIni'], 0, ',', '.')" hint="Mutasi keluar simpanan" tone="amber" icon="arrow-up-right" />
            <x-stat-card label="Saldo kas" :value="'Rp'.number_format($summary['saldoKas'], 0, ',', '.')" hint="Akumulasi kas transaksi" tone="sky" icon="banknotes" />
            <x-stat-card label="Rekening bersaldo" :value="$summary['rekeningAktif']" hint="Simpanan anggota aktif" tone="cyan" icon="users" />
        </div>

        <x-data-table title="Mutasi simpanan" :count="$transactions->total()" :sortable="false" :columns="['kode' => 'Kode / waktu', 'anggota' => 'Anggota', 'jenis' => 'Jenis simpanan', 'mutasi' => 'Mutasi', 'saldo' => 'Saldo sesudah', 'actor' => 'Petugas', 'actions' => 'Aksi']" :mobile-hidden="['jenis', 'actor']">
            <x-slot:filters>
                <form method="GET" class="grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-6 lg:items-end">
                    <flux:field class="lg:col-span-2">
                        <flux:label>Pencarian</flux:label>
                        <flux:input type="search" name="q" :value="$filters['q'] ?? ''" placeholder="Kode, nama, atau kode anggota" />
                    </flux:field>
                    <flux:field>
                        <flux:label>Jenis simpanan</flux:label>
                        <flux:select name="jenis"><option value="">Semua jenis</option>@foreach($types as $type)<option value="{{ $type->id }}" @selected((string) ($filters['jenis'] ?? '') === (string) $type->id)>{{ $type->nama_jenis }}</option>@endforeach</flux:select>
                    </flux:field>
                    <flux:field>
                        <flux:label>Mutasi</flux:label>
                        <flux:select name="transaksi"><option value="">Semua mutasi</option><option value="setoran" @selected(($filters['transaksi'] ?? '') === 'setoran')>Setoran</option><option value="penarikan" @selected(($filters['transaksi'] ?? '') === 'penarikan')>Penarikan</option></flux:select>
                    </flux:field>
                    <flux:field>
                        <flux:label>Dari tanggal</flux:label>
                        <flux:input type="date" name="tanggal_dari" :value="$filters['tanggal_dari'] ?? ''" />
                    </flux:field>
                    <div class="flex gap-2"><flux:button type="submit" variant="primary" class="flex-1">Terapkan</flux:button>@if($activeFilter)<flux:button :href="route('portal.savings.index', ['role' => $role])" variant="ghost">Reset</flux:button>@endif</div>
                </form>
            </x-slot:filters>

            @forelse ($transactions as $transaction)
                <tr class="hover:bg-slate-50/80">
                    <td class="whitespace-nowrap px-5 py-3"><p class="font-mono text-xs font-semibold text-slate-700">{{ $transaction->kode_transaksi }}</p><p class="mt-1 text-xs text-slate-500">{{ $transaction->tanggal_transaksi?->translatedFormat('d M Y, H:i') ?? '—' }}</p></td>
                    <td class="px-5 py-3 font-semibold text-slate-900"><a href="{{ route('portal.members.show', ['role' => $role, 'member' => $transaction->member]) }}" class="hover:text-teal-700">{{ $transaction->member?->nama_lengkap ?? 'Anggota dihapus' }}</a><p class="mt-1 font-mono text-xs font-normal text-slate-500">{{ $transaction->member?->kode_anggota }}</p></td>
                    <td class="hidden px-5 py-3 text-slate-600 md:table-cell">{{ $transaction->savingType?->nama_jenis ?? '—' }}</td>
                    <td class="whitespace-nowrap px-5 py-3"><x-status-badge :status="$transaction->jenis_transaksi" /><p class="mt-1 font-semibold {{ $transaction->jenis_transaksi === 'setoran' ? 'text-emerald-700' : 'text-rose-700' }}">{{ $transaction->jenis_transaksi === 'setoran' ? '+' : '−' }}<x-money :amount="$transaction->jumlah" /></p></td>
                    <td class="whitespace-nowrap px-5 py-3 font-semibold text-slate-800"><x-money :amount="$transaction->saldo_sesudah" /></td>
                    <td class="hidden px-5 py-3 text-slate-600 md:table-cell">{{ $transaction->actor?->nama_lengkap ?? 'Sistem lama' }}</td>
                    <td class="px-5 py-3 text-right"><x-action-menu label="Aksi {{ $transaction->kode_transaksi }}"><a href="{{ route('portal.savings.show', ['role' => $role, 'transaction' => $transaction]) }}" class="block rounded-lg px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Lihat detail</a><a href="{{ route('portal.savings.book', ['role' => $role, 'member' => $transaction->member]) }}" class="block rounded-lg px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Buku simpanan</a></x-action-menu></td>
                </tr>
            @empty
                <tr><td colspan="7" class="p-4"><x-empty-state title="Belum ada mutasi simpanan" description="Setoran dan penarikan yang dicatat akan muncul di sini." icon="wallet"><x-slot:action><flux:modal.trigger name="deposit-saving"><flux:button type="button" variant="primary" icon="plus">Catat setoran</flux:button></flux:modal.trigger></x-slot:action></x-empty-state></td></tr>
            @endforelse

            <x-slot:footer><x-server-pagination :paginator="$transactions" :query="collect(request()->query())->except(['page', 'per_page'])->all()" /></x-slot:footer>
        </x-data-table>
    </div>

    @foreach ([['deposit-saving', 'Setoran simpanan', 'Catat uang masuk ke rekening simpanan anggota.', 'portal.savings.deposit', 'setoran'], ['withdraw-saving', 'Penarikan simpanan', 'Pastikan saldo anggota mencukupi sebelum mencatat penarikan.', 'portal.savings.withdraw', 'penarikan']] as [$modal, $title, $description, $route, $kind])
        <x-short-form-modal :name="$modal" :title="$title" :description="$description">
            <form method="POST" action="{{ route($route, ['role' => $role]) }}" class="space-y-4">
                @csrf
                <x-required-hint />
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2"><x-member-picker :options="$memberOptions" required /></div>
                    <flux:field><flux:label>Jenis simpanan <x-required-mark /></flux:label><flux:select name="jenis_simpanan_id" required><option value="">Pilih jenis</option>@foreach($types as $type)<option value="{{ $type->id }}">{{ $type->nama_jenis }}</option>@endforeach</flux:select><flux:error name="jenis_simpanan_id" /></flux:field>
                    <flux:field><flux:label>Nominal <x-required-mark /></flux:label><flux:input type="number" name="jumlah" min="1" step="1" inputmode="numeric" placeholder="Contoh: 100000" required /><flux:error name="jumlah" /></flux:field>
                    <flux:field class="sm:col-span-2"><flux:label>Keterangan</flux:label><flux:textarea name="keterangan" rows="2" placeholder="Opsional" /><flux:error name="keterangan" /></flux:field>
                </div>
                <x-form-actions :note="$kind === 'penarikan' ? 'Penarikan tidak dapat dibatalkan melalui halaman ini.' : 'Saldo rekening dan kas akan diperbarui dalam satu transaksi.'" :submit-label="$kind === 'setoran' ? 'Simpan setoran' : 'Simpan penarikan'" />
            </form>
        </x-short-form-modal>
    @endforeach
</x-layouts.portal>
