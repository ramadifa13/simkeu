<?php

namespace App\Actions\Dashboard;

use App\Models\Anggota;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class GetDashboardData
{
    /** @return array<string, mixed> */
    public function handle(User $user, string $role): array
    {
        return match ($role) {
            'admin' => $this->admin(),
            'teller' => $this->teller($user),
            'anggota' => $this->member($user),
        };
    }

    /** @return array<string, mixed> */
    private function admin(): array
    {
        $today = now()->toDateString();
        $staff = $this->staffData(null, $today);

        return [
            'roleLabel' => 'Admin',
            'intro' => 'Pantau kesehatan operasional koperasi dari data legacy yang telah dimigrasikan.',
            'cards' => [
                $this->card('Anggota', (string) $staff['members']['total'], $staff['members']['active'].' aktif', 'users', 'portal.members.index'),
                $this->card('Simpanan', $this->money($staff['savingsTotal']), 'saldo real', 'wallet', 'portal.dashboard.drilldown', 'savings'),
                $this->card('Setoran hari ini', $this->money($staff['depositToday']['amount']), $staff['depositToday']['members'].' nasabah · '.$staff['depositToday']['count'].' trx', 'banknotes', 'portal.dashboard.drilldown', 'deposits-today'),
                $this->card('Pinjaman cair', $this->money($staff['loans']['total']), $staff['loans']['active'].' aktif', 'banknotes', 'portal.dashboard.drilldown', 'loans'),
                $this->card('Sisa tagihan', $this->money($staff['loans']['remaining']), 'pokok + bunga', 'clock', 'portal.dashboard.drilldown', 'receivables'),
                $this->card('Saldo kas', $this->money($staff['cashBalance']), 'tersedia', 'wallet', 'portal.dashboard.drilldown', 'cash'),
                $this->card('Waserda', $this->money((float) $staff['waserda']->amount), (int) $staff['waserda']->count.' trx', 'shopping-bag', 'portal.dashboard.drilldown', 'waserda'),
                $this->card('Pertumbuhan laba', number_format($staff['profit']['percent'], 1, ',', '.').'%', $staff['profit']['label'], 'chart-bar', 'portal.dashboard.drilldown', 'profit'),
            ],
            'queue' => $this->staffQueue($staff),
            'quickActions' => [['label' => 'Pengajuan', 'icon' => 'document-plus', 'module' => 'applications'], ['label' => 'Persetujuan', 'icon' => 'clipboard-document-check', 'module' => 'approvals'], ['label' => 'Angsuran', 'icon' => 'calendar-days', 'module' => 'installments'], ['label' => 'Anggota', 'icon' => 'users', 'route' => 'portal.members.index'], ['label' => 'Waserda', 'icon' => 'shopping-bag', 'module' => 'waserda'], ['label' => 'Laporan kas', 'icon' => 'chart-bar', 'module' => 'cash']],
            'staff' => $staff,
            'trends' => Cache::remember('dashboard:admin:trends:'.substr($today, 0, 7), 600, fn () => $this->trendSeries($today)),
            'alerts' => $this->loanAlerts($today),
            'activity' => $staff['transactions'],
            'periodLabel' => 'Data operasional per '.Carbon::parse($today)->translatedFormat('d M Y'),
        ];
    }

    /** @return array<string, mixed> */
    private function teller(User $user): array
    {
        $today = now()->toDateString();
        $staff = $this->staffData((int) $user->id, $today);

        return [
            'roleLabel' => 'Teller',
            'intro' => 'Fokus pada antrean dan transaksi operasional hari ini.',
            'cards' => [
                $this->card('Trx hari ini', (string) $staff['transactionsToday'], 'kas input saya', 'arrows-right-left', 'portal.dashboard.drilldown', 'deposits-today'),
                $this->card('Setoran hari ini', $this->money($staff['depositToday']['amount']), $staff['depositToday']['members'].' nasabah · '.$staff['depositToday']['count'].' trx', 'banknotes', 'portal.dashboard.drilldown', 'deposits-today'),
                $this->card('Anggota aktif', (string) $staff['members']['active'], 'dari '.$staff['members']['total'].' anggota', 'users', 'portal.members.index'),
            ],
            'queue' => $this->staffQueue($staff),
            'quickActions' => [['label' => 'Setoran', 'icon' => 'banknotes', 'module' => 'deposits-today'], ['label' => 'Angsuran', 'icon' => 'calendar-days', 'module' => 'installments'], ['label' => 'Pencairan', 'icon' => 'banknotes', 'module' => 'disbursements'], ['label' => 'Penarikan', 'icon' => 'arrow-down-circle', 'module' => 'savings'], ['label' => 'Waserda', 'icon' => 'shopping-bag', 'module' => 'waserda'], ['label' => 'Anggota', 'icon' => 'users', 'route' => 'portal.members.index']],
            'staff' => $staff,
            'alerts' => $this->loanAlerts($today),
            'activity' => $staff['transactions'],
            'periodLabel' => 'Rekap teller per '.Carbon::parse($today)->translatedFormat('d M Y'),
        ];
    }

    /** @return array<string, mixed> */
    private function member(User $user): array
    {
        $member = Anggota::query()->where('user_id', $user->id)->first();

        if (! $member) {
            return ['roleLabel' => 'Anggota', 'intro' => 'Akun ini belum terhubung ke data anggota.', 'cards' => [], 'alerts' => [], 'activity' => collect(), 'periodLabel' => 'Hubungi teller untuk menghubungkan akun Anda.', 'unlinkedMember' => true];
        }

        $loan = DB::table('pinjaman')->where('anggota_id', $member->id)->whereIn('status', \App\Support\LoanLifecycle::collectibleStatuses())->orderBy('jatuh_tempo')->first();
        $next = $loan ? DB::table('angsuran')->where('pinjaman_id', $loan->id)->where('status', '!=', 'lunas')->orderBy('tanggal_jatuh_tempo')->first() : null;

        $savingTypes = DB::table('simpanan as s')->join('jenis_simpanan as j', 'j.id', '=', 's.jenis_simpanan_id')->where('s.anggota_id', $member->id)->where('s.saldo', '>', 0)->whereNotIn('j.kode_jenis', ['ANG', 'BONUS', 'SHU'])->orderBy('j.id')->get(['j.kode_jenis', 'j.nama_jenis', 's.saldo']);
        $memberTransactions = DB::table('transaksi as t')->join('jenis_simpanan as j', 'j.id', '=', 't.jenis_simpanan_id')->where('t.anggota_id', $member->id)->whereNotIn('j.kode_jenis', ['ANG', 'BONUS', 'SHU'])->orderByDesc('t.tanggal_transaksi')->limit(10)->get(['t.kode_transaksi as title', 'j.nama_jenis as detail', 't.jenis_transaksi', 't.jumlah as amount', 't.tanggal_transaksi as occurred_at']);
        $schedules = DB::table('angsuran as a')->join('pinjaman as p', 'p.id', '=', 'a.pinjaman_id')->where('p.anggota_id', $member->id)->whereIn('p.status', \App\Support\LoanLifecycle::collectibleStatuses())->orderBy('p.id')->orderBy('a.angsuran_ke')->get(['p.kode_pinjaman', 'p.tenor', 'a.angsuran_ke', 'a.tanggal_jatuh_tempo', 'a.jumlah_angsuran', 'a.status']);
        $memberWaserda = $this->hasTable('waserda_transaksi') ? DB::table('waserda_transaksi')->where('anggota_id', $member->id)->orderByDesc('created_at')->limit(5)->get(['total_bayar as amount', 'created_at as occurred_at', 'items']) : collect();

        return [
            'roleLabel' => 'Anggota',
            'intro' => 'Ringkasan keuangan pribadi Anda. Hanya data milik akun ini yang ditampilkan.',
            'cards' => [
                $this->card('Total simpanan', $this->money((float) DB::table('simpanan')->where('anggota_id', $member->id)->sum('saldo')), 'saldo dari seluruh jenis simpanan', 'wallet', 'portal.dashboard.drilldown', 'savings-mine'),
                $this->card('Sisa pokok', $this->money((float) ($loan->sisa_pokok ?? 0)), $loan ? 'pinjaman '.$loan->kode_pinjaman : 'tidak ada pinjaman aktif', 'banknotes', 'portal.dashboard.drilldown', 'loans-mine'),
                $this->card('Angsuran berikutnya', $next ? $this->money((float) $next->jumlah_angsuran) : '—', $next ? 'jatuh tempo '.Carbon::parse($next->tanggal_jatuh_tempo)->translatedFormat('d M Y') : 'tidak ada tagihan aktif', 'calendar-days', 'portal.dashboard.drilldown', 'loans-mine'),
                $this->card('Status pengajuan', (string) DB::table('pengajuan_pinjaman')->where('anggota_id', $member->id)->whereIn('status', ['menunggu', 'disetujui'])->count(), 'pengajuan masih diproses', 'clipboard-document-check', 'portal.dashboard.drilldown', 'applications-mine'),
            ],
            'alerts' => $next ? [[
                'title' => 'Angsuran berikutnya',
                'description' => 'Angsuran ke-'.$next->angsuran_ke.' sebesar '.$this->money((float) $next->jumlah_angsuran).' jatuh tempo '.Carbon::parse($next->tanggal_jatuh_tempo)->translatedFormat('d M Y').'.',
                'tone' => Carbon::parse($next->tanggal_jatuh_tempo)->isPast() ? 'danger' : 'info',
                'module' => 'loans-mine',
            ]] : [],
            'activity' => $memberTransactions->take(6),
            'memberData' => compact('savingTypes', 'memberTransactions', 'schedules', 'memberWaserda'),
            'quickActions' => [['label' => 'Simpanan', 'icon' => 'wallet', 'module' => 'savings-mine'], ['label' => 'Pinjaman', 'icon' => 'banknotes', 'module' => 'loans-mine'], ['label' => 'Waserda', 'icon' => 'shopping-bag', 'module' => 'waserda-mine'], ['label' => 'Riwayat', 'icon' => 'clock', 'module' => 'savings-mine'], ['label' => 'SHU', 'icon' => 'calculator', 'module' => 'shu-mine'], ['label' => 'Profil', 'icon' => 'user-circle', 'route' => 'profile.edit']],
            'periodLabel' => 'Data pribadi diperbarui dari transaksi terakhir.',
            'member' => $member,
        ];
    }

    /**
     * Port read-only KPI dashboard native. $tellerId only scopes transactions
     * created by the current teller; portfolio and queue remain operationally shared.
     *
     * @return array<string, mixed>
     */
    private function staffData(?int $tellerId, string $today): array
    {
        $members = [
            'total' => DB::table('anggota')->count(),
            'active' => DB::table('anggota')->where('status', 'aktif')->count(),
            'inactive' => DB::table('anggota')->where('status', 'nonaktif')->count(),
            'today' => DB::table('anggota')->whereDate('tanggal_daftar', $today)->count(),
        ];
        $savings = DB::table('transaksi as t')->join('jenis_simpanan as j', 'j.id', '=', 't.jenis_simpanan_id')
            ->whereNotIn('j.kode_jenis', ['ANG', 'BONUS', 'SHU'])
            ->selectRaw("COALESCE(SUM(CASE WHEN t.jenis_transaksi = 'setoran' THEN t.jumlah ELSE -t.jumlah END), 0) as total")->value('total');
        $savingsByType = DB::table('jenis_simpanan as j')->leftJoin('transaksi as t', 't.jenis_simpanan_id', '=', 'j.id')
            ->where('j.status', 'aktif')->whereNotIn('j.kode_jenis', ['ANG', 'BONUS', 'SHU'])
            ->groupBy('j.id', 'j.kode_jenis', 'j.nama_jenis')->orderBy('j.id')
            ->get(['j.kode_jenis', 'j.nama_jenis', DB::raw("COALESCE(SUM(CASE WHEN t.jenis_transaksi = 'setoran' THEN t.jumlah ELSE -t.jumlah END), 0) as total")]);
        $depositQuery = DB::table('kas as k')->join('transaksi as t', 't.id', '=', 'k.transaksi_id')
            ->where('k.jenis', 'masuk')->whereDate('k.tanggal', $today)->where(function ($query): void { $query->whereNull('k.keterangan')->orWhere('k.keterangan', '')->orWhere('k.keterangan', 'not like', '%Waserda%'); });
        if ($tellerId) $depositQuery->where('t.user_id', $tellerId);
        $deposit = $depositQuery->selectRaw('COUNT(*) as count, COALESCE(SUM(k.jumlah),0) as amount, COUNT(DISTINCT t.anggota_id) as members')->first();
        $loan = DB::table('pinjaman')->selectRaw("COUNT(*) as total_count, COUNT(CASE WHEN status = 'aktif' THEN 1 END) as active, COUNT(CASE WHEN status = 'lunas' THEN 1 END) as paid, COUNT(CASE WHEN status = 'macet' THEN 1 END) as bad, COALESCE(SUM(jumlah_pinjaman),0) as total, COALESCE(SUM(CASE WHEN status = 'aktif' THEN sisa_pokok ELSE 0 END),0) as principal")->first();
        $remainingInterest = (float) DB::table('angsuran')->where('status', '!=', 'lunas')->sum('bunga');
        $waserda = $this->hasTable('waserda_transaksi') ? DB::table('waserda_transaksi')->selectRaw('COUNT(*) as count, COALESCE(SUM(total_bayar),0) as amount')->first() : (object) ['count' => 0, 'amount' => 0];
        $due = DB::table('angsuran as a')->join('pinjaman as p', 'p.id', '=', 'a.pinjaman_id')->join('anggota as m', 'm.id', '=', 'p.anggota_id')
            ->whereIn('a.status', ['belum_bayar', 'telat'])->whereBetween('a.tanggal_jatuh_tempo', [$today, now()->addDays(7)->toDateString()])->orderBy('a.tanggal_jatuh_tempo')->limit(8)
            ->get(['a.angsuran_ke', 'a.tanggal_jatuh_tempo', 'a.jumlah_angsuran', 'a.status', 'm.nama_lengkap', 'm.kode_anggota', 'p.kode_pinjaman']);
        $applications = DB::table('pengajuan_pinjaman')->selectRaw("COUNT(*) as total, COUNT(CASE WHEN status = 'menunggu' THEN 1 END) as pending, COUNT(CASE WHEN status = 'disetujui' THEN 1 END) as approved, COUNT(CASE WHEN status = 'ditolak' THEN 1 END) as rejected")->first();
        $readyDisbursement = DB::table('pengajuan_pinjaman as pp')->where('pp.status', 'disetujui')->whereNotExists(fn ($query) => $query->selectRaw(1)->from('pinjaman as p')->whereColumn('p.pengajuan_id', 'pp.id'))->count();
        $activeLoans = DB::table('pinjaman as p')->join('anggota as m', 'm.id', '=', 'p.anggota_id')->where('p.status', 'aktif')->orderByDesc('p.tanggal_pencairan')->limit(5)
            ->get(['p.id', 'p.kode_pinjaman', 'p.sisa_pokok', 'm.nama_lengkap', DB::raw("(select count(*) from angsuran where pinjaman_id=p.id and status='lunas') as paid_installments"), DB::raw('(select count(*) from angsuran where pinjaman_id=p.id) as total_installments'), DB::raw("(select coalesce(sum(bunga),0) from angsuran where pinjaman_id=p.id and status != 'lunas') as remaining_interest")]);
        $transactions = DB::table('transaksi as t')->join('anggota as m', 'm.id', '=', 't.anggota_id')->join('jenis_simpanan as j', 'j.id', '=', 't.jenis_simpanan_id')
            ->whereNotIn('j.kode_jenis', ['ANG', 'BONUS', 'SHU'])->when($tellerId, fn ($q) => $q->where('t.user_id', $tellerId))->orderByDesc('t.tanggal_transaksi')->limit(6)
            ->get(['t.kode_transaksi as title', 'm.nama_lengkap as actor', 'j.nama_jenis as detail', 't.jenis_transaksi', 't.jumlah as amount', 't.tanggal_transaksi as occurred_at']);
        $installments = DB::table('angsuran as a')->join('pinjaman as p', 'p.id', '=', 'a.pinjaman_id')->join('anggota as m', 'm.id', '=', 'p.anggota_id')->whereNotNull('a.tanggal_bayar')->orderByDesc('a.tanggal_bayar')->limit(6)
            ->get(['m.nama_lengkap', 'p.kode_pinjaman', 'a.angsuran_ke', 'a.jumlah_angsuran', 'a.denda', 'a.tanggal_bayar']);
        $profit = $this->profit($today);

        return compact('members', 'savings', 'savingsByType', 'deposit', 'loan', 'remainingInterest', 'waserda', 'due', 'applications', 'readyDisbursement', 'activeLoans', 'transactions', 'installments', 'profit') + [
            'savingsTotal' => (float) $savings,
            'cashBalance' => $this->latestCashBalance(),
            'loans' => ['total' => (float) $loan->total, 'active' => (int) $loan->active, 'remaining' => (float) $loan->principal + $remainingInterest],
            'depositToday' => ['count' => (int) $deposit->count, 'amount' => (float) $deposit->amount, 'members' => (int) $deposit->members],
            'transactionsToday' => $tellerId ? DB::table('kas as k')->leftJoin('transaksi as t', 't.id', '=', 'k.transaksi_id')->whereDate('k.tanggal', $today)->where('t.user_id', $tellerId)->count() : 0,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function staffQueue(array $staff): array
    {
        return [
            ['label' => 'Pengajuan menunggu', 'value' => (int) $staff['applications']->pending, 'module' => 'approvals', 'tone' => 'warning'],
            ['label' => 'Jatuh tempo 7 hari', 'value' => $staff['due']->count(), 'module' => 'receivables', 'tone' => 'danger'],
            ['label' => 'Siap cair', 'value' => (int) $staff['readyDisbursement'], 'module' => 'disbursements', 'tone' => 'success'],
        ];
    }

    /** @return array{percent: float, label: string} */
    private function profit(string $today): array
    {
        $monthStart = now()->startOfMonth()->toDateString();
        $row = DB::table('angsuran')->where('status', 'lunas')->whereBetween('tanggal_bayar', [$monthStart, $today])->whereBetween('tanggal_jatuh_tempo', [$monthStart, $today])->selectRaw('COALESCE(SUM(jumlah_angsuran + COALESCE(denda,0)),0) as installments, COALESCE(SUM(bunga),0) as interest')->first();
        $percent = (float) $row->installments > 0 ? round(((float) $row->interest / (float) $row->installments) * 100, 1) : 0.0;
        return ['percent' => $percent, 'label' => now()->translatedFormat('F Y').' · Laba '.$this->money((float) $row->interest)];
    }

    private function hasTable(string $table): bool
    {
        return Schema::hasTable($table);
    }

    /**
     * Six-month, read-only equivalent of the native admin trend cards.
     * Values remain arrays so the presentation can be reused by a chart, table,
     * or export without duplicating financial calculations in Blade.
     *
     * @return array<string, list<array{label:string,value:float|int,secondary:float|int}>>
     */
    private function trendSeries(string $today): array
    {
        $series = ['portfolio' => [], 'collectibility' => [], 'penalties' => [], 'profit' => []];

        for ($offset = 5; $offset >= 0; $offset--) {
            $start = now()->startOfMonth()->subMonths($offset);
            $end = $start->copy()->endOfMonth()->min(now());
            $label = $start->translatedFormat('M');
            $newMembers = DB::table('anggota')->whereBetween('tanggal_daftar', [$start->toDateString(), $end->toDateString()])->count();
            $memberTotal = DB::table('anggota')->whereDate('tanggal_daftar', '<=', $end->toDateString())->count();
            $late = DB::table('angsuran')->whereBetween('tanggal_jatuh_tempo', [$start->toDateString(), $end->toDateString()])
                ->where(function ($query): void { $query->where('status', 'telat')->orWhere('keterlambatan_hari', '>', 0)->orWhere(function ($paid): void { $paid->where('status', 'lunas')->whereNotNull('tanggal_bayar')->whereColumn('tanggal_bayar', '>', 'tanggal_jatuh_tempo'); }); })->count();
            $lateAmount = (float) DB::table('angsuran')->whereBetween('tanggal_jatuh_tempo', [$start->toDateString(), $end->toDateString()])
                ->where(function ($query): void { $query->where('status', 'telat')->orWhere('keterlambatan_hari', '>', 0); })->sum('jumlah_angsuran');
            $penaltyCreated = (float) DB::table('angsuran')->whereBetween('tanggal_jatuh_tempo', [$start->toDateString(), $end->toDateString()])->sum('denda');
            $penaltyPaid = (float) DB::table('angsuran')->where('status', 'lunas')->whereBetween('tanggal_bayar', [$start->toDateString(), $end->toDateString()])->sum('denda');
            $profit = DB::table('angsuran')->where('status', 'lunas')->whereBetween('tanggal_bayar', [$start->toDateString(), $end->toDateString()])->selectRaw('COALESCE(SUM(jumlah_angsuran + COALESCE(denda,0)),0) as installments, COALESCE(SUM(bunga),0) as interest')->first();
            $profitPercent = (float) $profit->installments > 0 ? round(((float) $profit->interest / (float) $profit->installments) * 100, 1) : 0.0;

            $series['portfolio'][] = ['label' => $label, 'value' => $newMembers, 'secondary' => $memberTotal];
            $series['collectibility'][] = ['label' => $label, 'value' => $late, 'secondary' => $lateAmount];
            $series['penalties'][] = ['label' => $label, 'value' => $penaltyCreated, 'secondary' => $penaltyPaid];
            $series['profit'][] = ['label' => $label, 'value' => $profitPercent, 'secondary' => (float) $profit->interest];
        }

        return $series;
    }

    /** @return list<array<string, mixed>> */
    private function loanAlerts(string $today): array
    {
        $overdue = DB::table('angsuran')->where('status', '!=', 'lunas')->whereDate('tanggal_jatuh_tempo', '<', $today)->count();
        $todayDue = DB::table('angsuran')->where('status', '!=', 'lunas')->whereDate('tanggal_jatuh_tempo', $today)->count();
        $badLoans = DB::table('pinjaman')->where('status', 'macet')->count();

        return collect([
            $overdue ? ['title' => $overdue.' angsuran melewati jatuh tempo', 'description' => 'Perlu tindak lanjut pembayaran dan verifikasi denda sesuai aturan pinjaman.', 'tone' => 'danger', 'module' => 'receivables'] : null,
            $todayDue ? ['title' => $todayDue.' angsuran jatuh tempo hari ini', 'description' => 'Prioritaskan antrean pembayaran agar tidak terlambat.', 'tone' => 'warning', 'module' => 'receivables'] : null,
            $badLoans ? ['title' => $badLoans.' pinjaman berstatus macet', 'description' => 'Tinjau layanan penanganan pinjaman bermasalah.', 'tone' => 'danger', 'module' => 'loans'] : null,
        ])->filter()->values()->all();
    }

    private function latestCashBalance(): float
    {
        return (float) (DB::table('kas')->orderByDesc('tanggal')->orderByDesc('id')->value('saldo_sesudah') ?? 0);
    }

    private function recentActivity(): Collection
    {
        return DB::table('activity_logs')->leftJoin('users', 'users.id', '=', 'activity_logs.user_id')->orderByDesc('activity_logs.created_at')->limit(6)->get(['activity_logs.action as title', 'users.nama_lengkap as actor', 'activity_logs.created_at as occurred_at']);
    }

    private function recentTransactions(): Collection
    {
        return DB::table('transaksi')->join('anggota', 'anggota.id', '=', 'transaksi.anggota_id')->orderByDesc('transaksi.tanggal_transaksi')->limit(6)->get(['transaksi.kode_transaksi as title', 'anggota.nama_lengkap as actor', 'transaksi.jenis_transaksi as detail', 'transaksi.jumlah as amount', 'transaksi.tanggal_transaksi as occurred_at']);
    }

    /** @return array<string, string|null> */
    private function card(string $label, string $value, string $hint, string $icon, string $route, ?string $module = null): array
    {
        return compact('label', 'value', 'hint', 'icon', 'route', 'module');
    }

    private function money(float $value): string
    {
        return 'Rp '.number_format($value, 0, ',', '.');
    }
}
