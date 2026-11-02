<x-layouts.portal :role="$role" title="Dashboard {{ $dashboard['roleLabel'] }}">
    <div class="mx-auto max-w-7xl space-y-5 lg:space-y-6" x-data="{ refreshing: false, async refresh() { if (this.refreshing) return; this.refreshing = true; try { const response = await fetch('{{ route('portal.dashboard', ['role' => $role]) }}', { headers: { Accept: 'application/json' } }); if (!response.ok) throw new Error('Gagal memuat'); const data = await response.json(); this.$el.querySelectorAll('[data-dashboard-card]').forEach((card, index) => { if (!data.cards[index]) return; card.querySelector('[data-dashboard-value]').textContent = data.cards[index].value; card.querySelector('[data-dashboard-hint]').textContent = data.cards[index].hint; }); this.$el.querySelectorAll('[data-dashboard-queue]').forEach((item, index) => { if (data.queue[index]) item.textContent = data.queue[index].value; }); this.$el.querySelector('[data-dashboard-period]').textContent = data.periodLabel + '. Angka keuangan ditampilkan sebagai ringkasan read-only; transaksi tetap dilakukan melalui modul berotorisasi.'; } catch (error) { window.dispatchEvent(new CustomEvent('toast', { detail: { message: 'Dashboard gagal diperbarui. Silakan coba lagi.', variant: 'danger' } })); } finally { this.refreshing = false; } } }">
        <x-page-header icon="squares-2x2" eyebrow="Portal {{ $dashboard['roleLabel'] }}" title="Ringkasan operasional" :description="$dashboard['intro']">
            <x-slot:actions>
                <button type="button" x-on:click="refresh()" x-bind:disabled="refreshing" class="inline-flex min-h-10 items-center justify-center gap-2 rounded-xl border border-white/40 bg-white/10 px-3.5 text-sm font-semibold text-white transition hover:bg-white/20 disabled:cursor-wait disabled:opacity-70"><span x-show="!refreshing">Muat ulang</span><span x-show="refreshing" x-cloak>Memuat...</span></button>
                @if ($role === 'admin')
                    <flux:button :href="route('portal.members.create', ['role' => $role])" variant="primary" color="teal" icon="plus">Tambah anggota</flux:button>
                @else
                    <flux:button :href="route('profile.edit')" variant="outline" icon="user-circle">Kelola akun</flux:button>
                @endif
            </x-slot:actions>
        </x-page-header>

        @if (data_get($dashboard, 'error'))
            <x-retry-state title="Dashboard belum dapat dimuat" description="Data operasional sedang tidak tersedia. Tidak ada perubahan data yang dilakukan.">
                <x-slot:action><flux:button :href="route('portal.dashboard', ['role' => $role])" variant="outline" icon="arrow-path">Muat ulang</flux:button></x-slot:action>
            </x-retry-state>
        @elseif (data_get($dashboard, 'unlinkedMember'))
            <x-empty-state title="Akun belum terhubung ke anggota" description="Teller atau admin perlu menghubungkan akun Anda ke data anggota sebelum ringkasan keuangan dapat ditampilkan." icon="user-minus">
                <x-slot:action><flux:button :href="route('profile.edit')" variant="outline">Periksa profil</flux:button></x-slot:action>
            </x-empty-state>
        @else
            <section aria-label="Ringkasan utama" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                @foreach ($dashboard['cards'] as $card)
                    @php($href = $card['route'] === 'portal.dashboard.drilldown' ? route($card['route'], ['role' => $role, 'report' => $card['module']]) : route($card['route'], ['role' => $role]))
                    <a href="{{ $href }}" data-dashboard-card class="ui-stat-card group block transition hover:-translate-y-0.5 hover:border-teal-200 hover:shadow-lg hover:shadow-teal-950/5 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-teal-600">
                        <div class="flex items-start justify-between gap-3"><p class="text-sm font-medium text-slate-600">{{ $card['label'] }}</p><span class="grid size-8 place-items-center rounded-lg bg-teal-50 text-teal-700"><flux:icon :name="$card['icon']" class="size-4" /></span></div>
                        <p data-dashboard-value class="mt-3 text-xl font-semibold tracking-tight text-slate-950">{{ $card['value'] }}</p>
                        <p data-dashboard-hint class="mt-1.5 text-xs leading-5 text-slate-500">{{ $card['hint'] }}</p>
                    </a>
                @endforeach
            </section>

            @if (filled($dashboard['queue'] ?? []))
                <section class="ui-panel p-4 sm:p-5" aria-labelledby="dashboard-queue-title">
                    <div class="mb-3 flex items-center justify-between gap-3"><h2 id="dashboard-queue-title" class="text-sm font-semibold text-slate-900">{{ $role === 'admin' ? 'KPI antrean' : 'Antrean kerja hari ini' }}</h2><span class="text-xs text-slate-500">Tindak lanjut prioritas</span></div>
                    <div class="grid gap-3 sm:grid-cols-3">@foreach ($dashboard['queue'] as $item)<a href="{{ route('portal.dashboard.drilldown', ['role' => $role, 'report' => $item['module']]) }}" class="flex items-center justify-between rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-3 text-sm transition hover:border-teal-200 hover:bg-teal-50"><span class="text-slate-700">{{ $item['label'] }}</span><strong data-dashboard-queue @class(['text-rose-700' => $item['tone'] === 'danger', 'text-amber-700' => $item['tone'] === 'warning', 'text-emerald-700' => $item['tone'] === 'success'])>{{ $item['value'] }}</strong></a>@endforeach</div>
                </section>
            @endif

            @if (filled($dashboard['quickActions'] ?? []))
                <section class="ui-panel p-4 sm:p-5" aria-labelledby="dashboard-quick-title">
                    <div class="mb-3 flex items-center justify-between gap-3"><h2 id="dashboard-quick-title" class="text-sm font-semibold text-slate-900">Aksi cepat</h2><span class="text-xs text-slate-500">Pintasan {{ $role === 'anggota' ? 'akun' : 'transaksi' }}</span></div>
                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 xl:grid-cols-6">@foreach ($dashboard['quickActions'] as $item)@php($href = isset($item['route']) ? route($item['route'], $item['route'] === 'portal.members.index' ? ['role' => $role] : []) : route('portal.dashboard.drilldown', ['role' => $role, 'report' => $item['module']]))<a href="{{ $href }}" class="flex min-h-20 flex-col justify-between rounded-xl border border-slate-200 bg-white p-3 text-sm font-semibold text-slate-700 transition hover:-translate-y-0.5 hover:border-teal-300 hover:bg-teal-50 hover:text-teal-800"><flux:icon :name="$item['icon']" class="size-5 text-teal-700" /><span>{{ $item['label'] }}</span></a>@endforeach</div>
                </section>
            @endif

            @if ($role === 'admin')
                <x-dashboard.analytics-tabs :trends="$dashboard['trends'] ?? []" />
            @endif

            @if ($staff = ($dashboard['staff'] ?? null))
                <div class="grid gap-6 xl:grid-cols-2">
                    <section class="ui-panel overflow-hidden"><div class="ui-table-heading"><div class="ui-table-title"><span class="ui-table-title-mark"><flux:icon name="wallet" class="size-4" /></span><h2>Saldo simpanan per jenis</h2></div></div><div class="divide-y divide-slate-100">@forelse ($staff['savingsByType'] as $saving)<div class="flex items-center justify-between gap-3 px-5 py-3"><div><span class="ui-code-badge">{{ $saving->kode_jenis }}</span><span class="ml-2 text-sm font-medium text-slate-700">{{ $saving->nama_jenis }}</span></div><strong class="text-sm text-emerald-700">Rp {{ number_format((float) $saving->total, 0, ',', '.') }}</strong></div>@empty <x-empty-state class="border-0 bg-transparent py-8" title="Belum ada saldo simpanan" /> @endforelse</div></section>
                    <section class="ui-panel overflow-hidden"><div class="ui-table-heading"><div class="ui-table-title"><span class="ui-table-title-mark"><flux:icon name="calendar-days" class="size-4" /></span><h2>Jatuh tempo 7 hari</h2></div></div><div class="divide-y divide-slate-100">@forelse ($staff['due'] as $due)<div class="flex items-center justify-between gap-3 px-5 py-3"><div><p class="text-sm font-semibold text-slate-800">{{ $due->nama_lengkap }}</p><p class="mt-1 text-xs text-slate-500">{{ $due->kode_pinjaman }} · {{ \Illuminate\Support\Carbon::parse($due->tanggal_jatuh_tempo)->translatedFormat('d M Y') }}</p></div><strong class="text-sm text-slate-700">Rp {{ number_format((float) $due->jumlah_angsuran, 0, ',', '.') }}</strong></div>@empty <x-empty-state class="border-0 bg-transparent py-8" title="Tidak ada jatuh tempo" description="Tidak ada angsuran pada tujuh hari berikutnya." /> @endforelse</div></section>
                </div>

                <section class="ui-panel overflow-hidden"><div class="ui-table-heading"><div class="ui-table-title"><span class="ui-table-title-mark"><flux:icon name="banknotes" class="size-4" /></span><h2>Pinjaman aktif</h2></div></div><div class="overflow-x-auto"><table class="w-full min-w-[34rem] text-sm"><thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-5 py-3">Anggota</th><th class="px-5 py-3">Progress</th><th class="px-5 py-3 text-right">Sisa tagihan</th></tr></thead><tbody class="divide-y divide-slate-100">@forelse ($staff['activeLoans'] as $loan)<tr><td class="px-5 py-3"><p class="font-semibold text-slate-800">{{ $loan->nama_lengkap }}</p><p class="mt-1 text-xs text-slate-500">{{ $loan->kode_pinjaman }}</p></td><td class="px-5 py-3 text-slate-600">{{ $loan->paid_installments }}/{{ $loan->total_installments }}</td><td class="px-5 py-3 text-right font-semibold text-slate-800">Rp {{ number_format((float) $loan->sisa_pokok + (float) $loan->remaining_interest, 0, ',', '.') }}</td></tr>@empty <tr><td colspan="3"><x-empty-state class="border-0 bg-transparent py-8" title="Belum ada pinjaman aktif" /></td></tr>@endforelse</tbody></table></div></section>
            @endif

            @if ($memberData = ($dashboard['memberData'] ?? null))
                <div class="grid gap-6 xl:grid-cols-[.8fr_1.2fr]">
                    <section class="ui-panel overflow-hidden"><div class="ui-table-heading"><div class="ui-table-title"><span class="ui-table-title-mark"><flux:icon name="wallet" class="size-4" /></span><h2>Jenis simpanan</h2></div></div><div class="divide-y divide-slate-100">@forelse ($memberData['savingTypes'] as $saving)<div class="flex items-center justify-between gap-3 px-5 py-3"><div><span class="ui-code-badge">{{ $saving->kode_jenis }}</span><span class="ml-2 text-sm font-medium text-slate-700">{{ $saving->nama_jenis }}</span></div><strong class="text-sm text-emerald-700">Rp {{ number_format((float) $saving->saldo, 0, ',', '.') }}</strong></div>@empty <x-empty-state class="border-0 bg-transparent py-8" title="Belum ada simpanan" /> @endforelse</div></section>
                    <section class="ui-panel overflow-hidden"><div class="ui-table-heading"><div class="ui-table-title"><span class="ui-table-title-mark"><flux:icon name="calendar-days" class="size-4" /></span><h2>Jadwal angsuran aktif</h2></div></div><div class="overflow-x-auto"><table class="w-full min-w-[32rem] text-sm"><thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-5 py-3">Pinjaman</th><th class="px-5 py-3">Jatuh tempo</th><th class="px-5 py-3 text-right">Nominal</th><th class="px-5 py-3">Status</th></tr></thead><tbody class="divide-y divide-slate-100">@forelse ($memberData['schedules'] as $schedule)<tr><td class="px-5 py-3 font-medium text-slate-800">{{ $schedule->kode_pinjaman }} · {{ $schedule->angsuran_ke }}</td><td class="px-5 py-3 text-slate-600">{{ \Illuminate\Support\Carbon::parse($schedule->tanggal_jatuh_tempo)->translatedFormat('d M Y') }}</td><td class="px-5 py-3 text-right font-semibold text-slate-800">Rp {{ number_format((float) $schedule->jumlah_angsuran, 0, ',', '.') }}</td><td class="px-5 py-3"><x-status-badge :status="$schedule->status === 'lunas' ? 'Lunas' : ($schedule->status === 'telat' ? 'Terlambat' : 'Belum bayar')" /></td></tr>@empty <tr><td colspan="4"><x-empty-state class="border-0 bg-transparent py-8" title="Tidak ada pinjaman aktif" /></td></tr>@endforelse</tbody></table></div></section>
                </div>
            @endif

            @if ($staff = ($dashboard['staff'] ?? null))
                <div class="grid gap-6 xl:grid-cols-3">
                    <section class="ui-panel overflow-hidden"><div class="ui-table-heading"><div class="ui-table-title"><span class="ui-table-title-mark"><flux:icon name="arrows-right-left" class="size-4" /></span><h2>Transaksi simpanan</h2></div></div><div class="divide-y divide-slate-100">@forelse ($staff['transactions'] as $transaction)<div class="flex items-start justify-between gap-3 px-5 py-3"><div class="min-w-0"><p class="truncate text-sm font-semibold text-slate-800">{{ $transaction->actor }}</p><p class="mt-1 text-xs text-slate-500">{{ $transaction->detail }} · {{ \Illuminate\Support\Carbon::parse($transaction->occurred_at)->translatedFormat('d M Y, H:i') }}</p></div><strong @class(['shrink-0 text-xs' => true, 'text-emerald-700' => $transaction->jenis_transaksi === 'setoran', 'text-amber-700' => $transaction->jenis_transaksi !== 'setoran'])>{{ $transaction->jenis_transaksi === 'setoran' ? '+' : '-' }}Rp {{ number_format((float) $transaction->amount, 0, ',', '.') }}</strong></div>@empty <x-empty-state class="border-0 bg-transparent py-8" title="Belum ada transaksi" /> @endforelse</div></section>
                    <section class="ui-panel overflow-hidden"><div class="ui-table-heading"><div class="ui-table-title"><span class="ui-table-title-mark"><flux:icon name="receipt-percent" class="size-4" /></span><h2>Angsuran terbaru</h2></div></div><div class="divide-y divide-slate-100">@forelse ($staff['installments'] as $installment)<div class="flex items-start justify-between gap-3 px-5 py-3"><div class="min-w-0"><p class="truncate text-sm font-semibold text-slate-800">{{ $installment->nama_lengkap }}</p><p class="mt-1 text-xs text-slate-500">{{ $installment->kode_pinjaman }} · ke-{{ $installment->angsuran_ke }}</p></div><strong class="shrink-0 text-xs text-slate-700">Rp {{ number_format((float) $installment->jumlah_angsuran + (float) $installment->denda, 0, ',', '.') }}</strong></div>@empty <x-empty-state class="border-0 bg-transparent py-8" title="Belum ada pembayaran" /> @endforelse</div></section>
                    <section class="ui-panel overflow-hidden"><div class="ui-table-heading"><div class="ui-table-title"><span class="ui-table-title-mark"><flux:icon name="shopping-bag" class="size-4" /></span><h2>Ringkasan Waserda</h2></div></div><div class="p-5"><p class="text-2xl font-semibold tracking-tight text-slate-950">Rp {{ number_format((float) $staff['waserda']->amount, 0, ',', '.') }}</p><p class="mt-2 text-sm text-slate-500">{{ $staff['waserda']->count }} transaksi keseluruhan.</p><a href="{{ route('portal.dashboard.drilldown', ['role' => $role, 'report' => 'waserda']) }}" class="mt-5 inline-flex text-sm font-semibold text-teal-700 hover:text-teal-900">Lihat transaksi Waserda <span aria-hidden="true" class="ml-1">→</span></a></div></section>
                </div>
            @endif

            <div class="grid gap-6 xl:grid-cols-[1.15fr_.85fr]">
                <section class="ui-panel overflow-hidden" aria-labelledby="dashboard-alert-title">
                    <div class="ui-table-heading"><div class="ui-table-title"><span class="ui-table-title-mark"><flux:icon name="exclamation-triangle" class="size-4" /></span><h2 id="dashboard-alert-title">Perlu perhatian</h2></div></div>
                    @forelse ($dashboard['alerts'] as $alert)
                        <a href="{{ route('portal.dashboard.drilldown', ['role' => $role, 'report' => $alert['module']]) }}" @class(['flex gap-3 border-t border-slate-100 px-5 py-4 transition hover:bg-slate-50', 'bg-rose-50/40' => $alert['tone'] === 'danger', 'bg-amber-50/45' => $alert['tone'] === 'warning'])>
                            <span @class(['mt-0.5 grid size-8 shrink-0 place-items-center rounded-lg', 'bg-rose-100 text-rose-700' => $alert['tone'] === 'danger', 'bg-amber-100 text-amber-700' => $alert['tone'] === 'warning', 'bg-sky-100 text-sky-700' => $alert['tone'] === 'info'])><flux:icon name="bell-alert" class="size-4" /></span>
                            <span><span class="block text-sm font-semibold text-slate-900">{{ $alert['title'] }}</span><span class="mt-1 block text-sm leading-5 text-slate-600">{{ $alert['description'] }}</span></span>
                        </a>
                    @empty
                        <x-empty-state class="border-0 bg-transparent py-10" title="Tidak ada perhatian mendesak" description="Antrean jatuh tempo dan pinjaman bermasalah akan muncul di sini." icon="check-circle" />
                    @endforelse
                </section>

                <section class="ui-panel overflow-hidden" aria-labelledby="dashboard-activity-title">
                    <div class="ui-table-heading"><div class="ui-table-title"><span class="ui-table-title-mark"><flux:icon name="clock" class="size-4" /></span><h2 id="dashboard-activity-title">Aktivitas terbaru</h2></div></div>
                    <div class="divide-y divide-slate-100">
                        @forelse ($dashboard['activity'] as $activity)
                            <div class="px-5 py-3.5"><div class="flex items-start justify-between gap-3"><div class="min-w-0"><p class="truncate text-sm font-semibold text-slate-900">{{ $activity->title }}</p><p class="mt-1 text-xs text-slate-500">{{ $activity->actor ?? ucfirst(str_replace('_', ' ', $activity->detail ?? 'Aktivitas sistem')) }}</p></div>@if (isset($activity->amount))<span class="shrink-0 text-xs font-semibold text-teal-700">Rp {{ number_format((float) $activity->amount, 0, ',', '.') }}</span>@endif</div><p class="mt-1 text-xs text-slate-400">{{ \Illuminate\Support\Carbon::parse($activity->occurred_at)->translatedFormat('d M Y, H:i') }}</p></div>
                        @empty
                            <x-empty-state class="border-0 bg-transparent py-10" title="Belum ada aktivitas" description="Aktivitas yang tercatat akan muncul di sini." icon="clock" />
                        @endforelse
                    </div>
                </section>
            </div>

            <p data-dashboard-period class="px-1 text-xs text-slate-500">{{ $dashboard['periodLabel'] }}. Angka keuangan ditampilkan sebagai ringkasan read-only; transaksi tetap dilakukan melalui modul berotorisasi.</p>
        @endif
    </div>
</x-layouts.portal>
