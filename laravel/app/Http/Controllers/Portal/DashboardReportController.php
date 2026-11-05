<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Anggota;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class DashboardReportController extends Controller
{
    public function __invoke(Request $request, string $role, string $report): View
    {
        try {
            $definition = $this->definition($request->user(), $role, $report);
        } catch (QueryException) {
            $definition = [
                'title' => 'Data dashboard belum tersedia',
                'description' => 'Sumber data untuk detail ini belum dapat dibaca. Tidak ada perubahan data yang dilakukan.',
                'headers' => ['Informasi'],
                'rows' => collect(),
                'empty' => 'Silakan muat ulang atau hubungi administrator bila masalah berlanjut.',
            ];
        }

        abort_unless($definition !== null, 404);

        return view('portal.dashboard-report', compact('role', 'definition'));
    }

    /** @return array{title:string,description:string,headers:list<string>,rows:\Illuminate\Support\Collection<int, object>,empty:string}|null */
    private function definition(User $user, string $role, string $report): ?array
    {
        $staffOnly = ['savings', 'deposits-today', 'loans', 'receivables', 'applications', 'approvals', 'installments', 'disbursements', 'waserda', 'profit'];
        $adminOnly = ['cash'];
        $memberOnly = ['savings-mine', 'loans-mine', 'applications-mine', 'waserda-mine', 'shu-mine'];

        if (in_array($report, $adminOnly, true) && $role !== 'admin') return null;
        if (in_array($report, $staffOnly, true) && ! in_array($role, ['admin', 'teller'], true)) return null;
        if (in_array($report, $memberOnly, true) && $role !== 'anggota') return null;

        $today = now()->toDateString();
        $memberId = $role === 'anggota' ? Anggota::query()->where('user_id', $user->id)->value('id') : null;

        return match ($report) {
            'savings' => $this->table('Riwayat simpanan', 'Seluruh transaksi simpanan yang menjadi dasar ringkasan saldo dashboard.', ['Kode', 'Anggota', 'Jenis', 'Transaksi', 'Nominal', 'Tanggal'], $this->savings(), 'Belum ada transaksi simpanan.', $report),
            'deposits-today' => $this->table('Setoran hari ini', 'Filter diterapkan: setoran yang tercatat pada '.now()->translatedFormat('d M Y').'.', ['Kode', 'Anggota', 'Jenis', 'Transaksi', 'Nominal', 'Waktu'], $this->savingsQuery()->where('t.jenis_transaksi', 'setoran')->whereDate('t.tanggal_transaksi', $today)->get(), 'Belum ada setoran hari ini.', $report),
            'cash' => $this->table('Buku kas', 'Filter diterapkan: urutan transaksi kas terbaru.', ['Tanggal', 'Jenis', 'Keterangan', 'Nominal', 'Saldo sesudah'], DB::table('kas')->orderByDesc('tanggal')->orderByDesc('id')->limit(100)->get(['tanggal', 'jenis', 'keterangan', 'jumlah', 'saldo_sesudah']), 'Belum ada catatan kas.', $report),
            'loans' => $this->table('Pinjaman', 'Filter diterapkan: seluruh pinjaman, terbaru lebih dulu.', ['Kode', 'Anggota', 'Status', 'Plafon', 'Sisa pokok', 'Jatuh tempo'], $this->loans(), 'Belum ada pinjaman.', $report),
            'receivables' => $this->table(
                'Sisa tagihan',
                'Angsuran belum lunas. Ketuk baris untuk membuka detail pinjaman dan fokus ke jadwal angsuran.',
                ['Pinjaman', 'Anggota', 'Ke', 'Jatuh tempo', 'Nominal', 'Status'],
                $this->receivables(),
                'Tidak ada tagihan aktif.',
                $report
            ),
            'applications' => $this->table('Pengajuan pinjaman', 'Filter diterapkan: seluruh pengajuan dan status prosesnya.', ['Anggota', 'Jenis', 'Nominal', 'Status', 'Tanggal'], $this->applications(), 'Belum ada pengajuan.', $report),
            'approvals' => $this->table('Persetujuan pinjaman', 'Filter diterapkan: pengajuan yang memerlukan tindak lanjut.', ['Anggota', 'Jenis', 'Nominal', 'Status', 'Tanggal'], $this->applicationsQuery()->whereIn('pp.status', ['menunggu', 'disetujui'])->get(), 'Tidak ada pengajuan yang perlu diproses.', $report),
            'installments' => $this->table('Angsuran', 'Filter diterapkan: jadwal dan pembayaran angsuran terbaru.', ['Pinjaman', 'Anggota', 'Ke', 'Jatuh tempo', 'Nominal', 'Status'], $this->installments(), 'Belum ada angsuran.', $report),
            'disbursements' => $this->table('Siap pencairan', 'Filter diterapkan: pengajuan disetujui yang belum memiliki pinjaman cair.', ['Anggota', 'Jenis', 'Nominal', 'Status', 'Tanggal'], $this->applicationsQuery()->where('pp.status', 'disetujui')->whereNotExists(fn (Builder $query) => $query->selectRaw(1)->from('pinjaman as p')->whereColumn('p.pengajuan_id', 'pp.id'))->get(), 'Tidak ada pencairan yang menunggu.', $report),
            'waserda' => $this->waserda(),
            'profit' => $this->table('Realisasi laba bulan ini', 'Filter diterapkan: angsuran lunas pada bulan berjalan.', ['Pinjaman', 'Anggota', 'Ke', 'Jatuh tempo', 'Nominal', 'Status'], $this->installmentsQuery()->where('a.status', 'lunas')->whereBetween('a.tanggal_bayar', [now()->startOfMonth()->toDateString(), $today])->get(), 'Belum ada pembayaran lunas bulan ini.', $report),
            'savings-mine' => $this->table('Simpanan saya', 'Filter diterapkan: hanya transaksi milik akun Anda.', ['Kode', 'Jenis', 'Transaksi', 'Nominal', 'Tanggal'], $this->savings($memberId, false), 'Belum ada transaksi simpanan.', $report),
            'loans-mine' => $this->table('Pinjaman saya', 'Filter diterapkan: hanya pinjaman milik akun Anda.', ['Kode', 'Status', 'Plafon', 'Sisa pokok', 'Jatuh tempo'], $this->loans($memberId, false), 'Belum ada pinjaman.', $report),
            'applications-mine' => $this->table('Pengajuan saya', 'Filter diterapkan: hanya pengajuan milik akun Anda.', ['Jenis', 'Nominal', 'Status', 'Tanggal'], $this->applications($memberId, false), 'Belum ada pengajuan.', $report),
            'waserda-mine' => $this->waserda($memberId),
            'shu-mine' => $this->table('SHU saya', 'Filter diterapkan: hanya data SHU milik akun Anda.', ['Informasi'], collect(), 'Data SHU belum tersedia pada periode ini.', $report),
            default => null,
        };
    }

    private function table(string $title, string $description, array $headers, $rows, string $empty, ?string $report = null): array
    {
        return compact('title', 'description', 'headers', 'rows', 'empty', 'report');
    }

    private function receivables()
    {
        return DB::table('angsuran as a')
            ->join('pinjaman as p', 'p.id', '=', 'a.pinjaman_id')
            ->join('anggota as m', 'm.id', '=', 'p.anggota_id')
            ->where('a.status', '!=', 'lunas')
            ->whereIn('p.status', \App\Support\LoanLifecycle::collectibleStatuses())
            ->orderBy('a.tanggal_jatuh_tempo')
            ->limit(100)
            ->get([
                'p.id as loan_id',
                'a.id as installment_id',
                'p.kode_pinjaman',
                'm.nama_lengkap',
                'm.kode_anggota',
                'a.angsuran_ke',
                'a.tanggal_jatuh_tempo',
                'a.jumlah_angsuran as nominal',
                'a.status',
            ]);
    }

    private function savings(?int $memberId = null, bool $withMember = true)
    {
        $query = $this->savingsQuery($memberId, $withMember);
        return $query->get();
    }

    private function savingsQuery(?int $memberId = null, bool $withMember = true): Builder
    {
        $query = DB::table('transaksi as t')->join('jenis_simpanan as j', 'j.id', '=', 't.jenis_simpanan_id')->when($withMember, fn (Builder $q) => $q->join('anggota as m', 'm.id', '=', 't.anggota_id'))->whereNotIn('j.kode_jenis', ['ANG', 'BONUS', 'SHU'])->when($memberId, fn (Builder $q) => $q->where('t.anggota_id', $memberId))->orderByDesc('t.tanggal_transaksi')->limit(100);
        return $withMember ? $query->select(['t.kode_transaksi', 'm.nama_lengkap', 'j.nama_jenis', 't.jenis_transaksi', 't.jumlah', 't.tanggal_transaksi']) : $query->select(['t.kode_transaksi', 'j.nama_jenis', 't.jenis_transaksi', 't.jumlah', 't.tanggal_transaksi']);
    }

    private function loans(?int $memberId = null, bool $withMember = true)
    {
        $query = DB::table('pinjaman as p')->when($withMember, fn (Builder $q) => $q->join('anggota as m', 'm.id', '=', 'p.anggota_id'))->when($memberId, fn (Builder $q) => $q->where('p.anggota_id', $memberId))->orderByDesc('p.created_at')->limit(100);
        return $withMember ? $query->get(['p.kode_pinjaman', 'm.nama_lengkap', 'p.status', 'p.jumlah_pinjaman', 'p.sisa_pokok', 'p.jatuh_tempo']) : $query->get(['p.kode_pinjaman', 'p.status', 'p.jumlah_pinjaman', 'p.sisa_pokok', 'p.jatuh_tempo']);
    }

    private function installments()
    {
        return $this->installmentsQuery()->get();
    }

    private function installmentsQuery(): Builder
    {
        return DB::table('angsuran as a')->join('pinjaman as p', 'p.id', '=', 'a.pinjaman_id')->join('anggota as m', 'm.id', '=', 'p.anggota_id')->orderByDesc('a.tanggal_jatuh_tempo')->limit(100)->select(['p.kode_pinjaman', 'm.nama_lengkap', 'a.angsuran_ke', 'a.tanggal_jatuh_tempo', 'a.jumlah_angsuran', 'a.status']);
    }

    private function applications(?int $memberId = null, bool $withMember = true)
    {
        return $this->applicationsQuery($memberId, $withMember)->get();
    }

    private function applicationsQuery(?int $memberId = null, bool $withMember = true): Builder
    {
        $query = DB::table('pengajuan_pinjaman as pp')->join('jenis_pinjaman as jp', 'jp.id', '=', 'pp.jenis_pinjaman_id')->when($withMember, fn (Builder $q) => $q->join('anggota as m', 'm.id', '=', 'pp.anggota_id'))->when($memberId, fn (Builder $q) => $q->where('pp.anggota_id', $memberId))->orderByDesc('pp.created_at')->limit(100);
        return $withMember ? $query->select(['m.nama_lengkap', 'jp.nama_jenis', 'pp.jumlah_pinjaman', 'pp.status', 'pp.created_at']) : $query->select(['jp.nama_jenis', 'pp.jumlah_pinjaman', 'pp.status', 'pp.created_at']);
    }

    private function waserda(?int $memberId = null): array
    {
        if (! Schema::hasTable('waserda_transaksi')) {
            return $this->table('Waserda', 'Data transaksi Waserda belum tersedia pada database.', ['Informasi'], collect(), 'Tabel Waserda belum tersedia.', 'waserda');
        }
        $query = DB::table('waserda_transaksi as w')->join('anggota as m', 'm.id', '=', 'w.anggota_id')->when($memberId, fn (Builder $q) => $q->where('w.anggota_id', $memberId))->orderByDesc('w.created_at')->limit(100);

        return $this->table(
            $memberId ? 'Waserda saya' : 'Transaksi Waserda',
            $memberId ? 'Filter diterapkan: hanya transaksi milik akun Anda.' : 'Filter diterapkan: transaksi Waserda terbaru.',
            $memberId ? ['Tanggal', 'Total', 'Item'] : ['Tanggal', 'Anggota', 'Total', 'Item'],
            $memberId ? $query->get(['w.created_at', 'w.total_bayar', 'w.items']) : $query->get(['w.created_at', 'm.nama_lengkap', 'w.total_bayar', 'w.items']),
            'Belum ada transaksi Waserda.',
            $memberId ? 'waserda-mine' : 'waserda'
        );
    }
}
