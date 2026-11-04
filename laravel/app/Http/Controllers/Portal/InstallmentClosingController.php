<?php

namespace App\Http\Controllers\Portal;

use App\Actions\Audit\RecordActivity;
use App\Actions\Loans\CloseInstallmentPeriodAction;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class InstallmentClosingController extends Controller
{
    public function index(Request $request, string $role, CloseInstallmentPeriodAction $closing): View
    {
        $this->authorize('manage-master-data');
        $filters = $request->validate(['bulan' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'], 'q' => ['nullable', 'string', 'max:20'], 'status' => ['nullable', 'in:terkunci,dibuka']]);
        $month = $filters['bulan'] ?? now()->format('Y-m');
        $periods = DB::table('closing_angsuran_bulanan')->when(filled($filters['q'] ?? null), fn ($query) => $query->where('bulan', 'like', '%'.$filters['q'].'%'))->when(filled($filters['status'] ?? null), fn ($query) => $query->where('status', $filters['status']))->orderByDesc('bulan')->paginate(12)->withQueryString();

        return view('portal.loans.closing', ['role' => $role, 'month' => $month, 'filters' => $filters, 'preview' => $closing->preview($month), 'periods' => $periods]);
    }

    public function show(Request $request, string $role, int $closing): View
    {
        $this->authorize('manage-master-data');
        $period = DB::table('closing_angsuran_bulanan as c')
            ->leftJoin('users as closer', 'closer.id', '=', 'c.ditutup_oleh')
            ->leftJoin('users as opener', 'opener.id', '=', 'c.dibuka_oleh')
            ->where('c.id', $closing)
            ->first(['c.*', 'closer.nama_lengkap as nama_penutup', 'opener.nama_lengkap as nama_pembuka']);
        abort_unless($period, 404);

        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $installments = DB::table('closing_angsuran_detail as d')
            ->join('angsuran as i', 'i.id', '=', 'd.angsuran_id')
            ->join('pinjaman as p', 'p.id', '=', 'i.pinjaman_id')
            ->join('anggota as a', 'a.id', '=', 'p.anggota_id')
            ->where('d.closing_id', $period->id)
            ->when(filled($filters['q'] ?? null), function ($query) use ($filters): void {
                $term = '%'.$filters['q'].'%';
                $query->where(fn ($search) => $search->where('a.nama_lengkap', 'like', $term)->orWhere('a.kode_anggota', 'like', $term)->orWhere('p.kode_pinjaman', 'like', $term));
            })
            ->orderBy('i.angsuran_ke')
            ->select([
                'd.id',
                'd.nominal',
                'd.tanggal_bayar',
                'i.angsuran_ke',
                'p.id as loan_id',
                'p.kode_pinjaman',
                'a.nama_lengkap',
                'a.kode_anggota',
            ])
            ->paginate(15)->withQueryString();

        return view('portal.loans.closing-show', compact('role', 'period', 'installments', 'filters'));
    }

    public function close(Request $request, string $role, CloseInstallmentPeriodAction $closing, RecordActivity $audit): RedirectResponse
    {
        $this->authorize('manage-master-data');
        $data = $request->validate(['bulan' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'], 'catatan' => ['nullable', 'string', 'max:500']]);
        try {
            $result = $closing->close($data['bulan'], $request->user(), $data['catatan'] ?? '');
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['closing' => $e->getMessage()]);
        }
        $audit->handle($request->user(), 'loan.period.closed', 'closing_angsuran_bulanan', $result['id'], ['bulan' => $data['bulan'], 'total' => $result['total']], $request);

        return back()->with('status', 'Periode angsuran berhasil dikunci.');
    }

    public function reopen(Request $request, string $role, CloseInstallmentPeriodAction $closing, RecordActivity $audit): RedirectResponse
    {
        $this->authorize('manage-master-data');
        $data = $request->validate(['bulan' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'], 'catatan' => ['required', 'string', 'min:5', 'max:500']]);
        try {
            $closing->reopen($data['bulan'], $request->user(), $data['catatan']);
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['closing' => $e->getMessage()]);
        }
        $audit->handle($request->user(), 'loan.period.reopened', 'closing_angsuran_bulanan', null, ['bulan' => $data['bulan']], $request);

        return back()->with('status', 'Periode angsuran dibuka kembali.');
    }
}
