<?php

namespace App\Http\Controllers\Portal;

use App\Actions\Audit\RecordActivity;
use App\Actions\Loans\CompleteDisbursementPhotosAction;
use App\Actions\Loans\MarkInstallmentReversalSeenAction;
use App\Actions\Loans\RefreshLoanPenaltiesAction;
use App\Actions\Members\ResolveMemberDisbursementPhoto;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Portal\Concerns\InteractsWithLoans;
use App\Http\Requests\CompleteDisbursementPhotosRequest;
use App\Models\Anggota;
use App\Models\Angsuran;
use App\Models\Pinjaman;
use App\Support\ImageStorage;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LoanController extends Controller
{
    use InteractsWithLoans;

    public function index(Request $request, string $role): View
    {
        $this->authorize('view-loans');
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['aktif', 'lunas', 'macet'])],
            'hari' => ['nullable', 'integer', 'between:1,7'],
        ]);

        $query = DB::table('pinjaman as p')
            ->join('anggota as a', 'a.id', '=', 'p.anggota_id')
            ->join('jenis_pinjaman as jp', 'jp.id', '=', 'p.jenis_pinjaman_id')
            ->leftJoin('pengajuan_pinjaman as pp', 'pp.id', '=', 'p.pengajuan_id')
            ->select(['p.*', 'a.kode_anggota', 'a.nama_lengkap', 'jp.nama_jenis', 'pp.hari_angsuran']);

        if ($role === 'anggota') {
            $query->where('p.anggota_id', Anggota::query()->where('user_id', $request->user()->id)->value('id'));
        }
        if (filled($filters['q'] ?? null)) {
            $term = '%'.$filters['q'].'%';
            $query->where(fn ($q) => $q->where('p.kode_pinjaman', 'like', $term)->orWhere('a.nama_lengkap', 'like', $term)->orWhere('a.kode_anggota', 'like', $term));
        }
        if (filled($filters['status'] ?? null)) {
            $query->where('p.status', $filters['status']);
        }
        if (filled($filters['hari'] ?? null)) {
            $query->where('pp.hari_angsuran', $filters['hari']);
        }

        $loans = $query->orderByDesc('p.tanggal_pencairan')->paginate(10)->withQueryString();
        $photos = app(ResolveMemberDisbursementPhoto::class)->map(
            $loans->getCollection()->pluck('anggota_id')->map(fn ($id) => (int) $id)->all()
        );
        $loans->getCollection()->transform(function (object $loan) use ($photos, $role): object {
            $path = $photos[(int) $loan->anggota_id] ?? null;
            if (! $path || ! str_starts_with($path, 'pencairan/') || ! Storage::disk('local')->exists($path)) {
                $loan->avatar_url = null;

                return $loan;
            }

            $loan->avatar_url = $role === 'anggota'
                ? route('profile.photo').'?v='.md5($path)
                : route('portal.members.avatar', ['role' => $role, 'member' => $loan->anggota_id]).'?v='.md5($path);

            return $loan;
        });

        return view('portal.loans.index', [
            'role' => $role,
            'filters' => $filters,
            'loans' => $loans,
        ]);
    }

    public function show(Request $request, string $role, int $loan, RefreshLoanPenaltiesAction $refresh, MarkInstallmentReversalSeenAction $markReversalSeen): View
    {
        $this->authorize('view-loans');
        $detail = $this->loanForRole($request, $role, $loan);
        if (\App\Support\LoanLifecycle::isCollectible($detail->status)) {
            $refresh->handle($loan, today()->toDateString());
            $detail = $this->loanForRole($request, $role, $loan);
        }

        if ($role === 'teller') {
            $markReversalSeen->handle($request->user(), $loan);
        }

        $installmentQuery = Angsuran::query()->from('angsuran as a')->where('a.pinjaman_id', $loan)->orderBy('a.angsuran_ke')->select('a.*');
        if (Schema::hasTable('layanan_bayar_denda')) {
            $installmentQuery->selectSub(
                DB::table('layanan_bayar_denda')->selectRaw('COALESCE(SUM(jumlah), 0)')->whereColumn('angsuran_id', 'a.id'),
                'denda_dibayar'
            );
        }
        $installments = $installmentQuery->get();
        if (! Schema::hasTable('layanan_bayar_denda')) {
            $installments->each(fn ($row) => $row->denda_dibayar = 0);
        }

        $reversalRequests = collect();
        if (Schema::hasTable('pengajuan_pembatalan_angsuran')) {
            $reversalRequests = DB::table('pengajuan_pembatalan_angsuran')
                ->whereIn('angsuran_id', $installments->pluck('id'))
                ->orderByDesc('id')->get()->unique('angsuran_id')->keyBy('angsuran_id');
        }

        $collateralRequest = Schema::hasTable('layanan_jaminan_kembali')
            ? DB::table('layanan_jaminan_kembali')->where('pinjaman_id', $loan)->first()
            : null;

        $agreement = Schema::hasTable('perjanjian_pinjaman')
            ? DB::table('perjanjian_pinjaman')->where('pinjaman_id', $loan)->orderByDesc('id')->first(['id', 'nomor_perjanjian', 'versi', 'ditandatangani_at'])
            : null;

        $needsDisbursementPhoto = Schema::hasColumn('pinjaman', 'foto_pencairan')
            && blank($detail->foto_pencairan ?? null)
            && $role !== 'anggota';

        $cartItems = collect($request->session()->get(LoanCartController::CART_KEY, []));
        $cartIds = $cartItems->pluck('installment_id')->map(fn ($id) => (int) $id)->all();
        $cartOnThisLoan = $installments->whereIn('id', $cartIds)->values();

        return view('portal.loans.show', [
            'role' => $role,
            'loan' => $detail,
            'installments' => $installments,
            'reversalRequests' => $reversalRequests,
            'settlement' => $this->settlementSummary($loan),
            'collateral' => Pinjaman::query()->find($loan)?->jaminanPinjaman,
            'collateralRequest' => $collateralRequest,
            'agreement' => $agreement,
            'needsDisbursementPhoto' => $needsDisbursementPhoto,
            'cartIds' => $cartIds,
            'cartCount' => count($cartIds),
            'cartLabels' => $cartOnThisLoan->pluck('angsuran_ke')->all(),
            'cartOnThisLoanCount' => $cartOnThisLoan->count(),
        ]);
    }

    public function completeDisbursementPhotos(CompleteDisbursementPhotosRequest $request, string $role, int $loan, CompleteDisbursementPhotosAction $complete, RecordActivity $audit, ImageStorage $images): RedirectResponse
    {
        $this->loanForRole($request, $role, $loan);

        $stored = [
            'foto_nasabah' => $images->store($request->file('foto_nasabah'), 'pencairan'),
            'foto_barang' => null,
            'foto_nasabah_barang' => null,
        ];
        try {
            if ($request->hasFile('foto_barang_jaminan')) {
                $stored['foto_barang'] = $images->store($request->file('foto_barang_jaminan'), 'jaminan');
            }
            if ($request->hasFile('foto_nasabah_barang')) {
                $stored['foto_nasabah_barang'] = $images->store($request->file('foto_nasabah_barang'), 'jaminan');
            }
            $complete->handle($loan, $request->user(), $stored);
        } catch (DomainException $e) {
            Storage::disk('local')->delete(array_filter($stored));
            throw ValidationException::withMessages(['foto_nasabah' => $e->getMessage()]);
        }

        $audit->handle($request->user(), 'loan.disbursement.photos_completed', 'pinjaman', $loan, [], $request);

        return back()->with('status', 'Foto pencairan berhasil dilengkapi.');
    }

    public function agreement(Request $request, string $role, int $loan): View
    {
        $this->authorize('view-loans');
        $detail = $this->loanForRole($request, $role, $loan);
        abort_unless(Schema::hasTable('perjanjian_pinjaman'), 404);
        $agreement = DB::table('perjanjian_pinjaman')->where('pinjaman_id', $loan)->orderByDesc('id')->first();
        abort_unless($agreement, 404);

        $snapshot = json_decode((string) ($agreement->snapshot_json ?? '{}'), true) ?: [];
        $attachments = Schema::hasTable('lampiran_perjanjian')
            ? DB::table('lampiran_perjanjian')->where('perjanjian_id', $agreement->id)->orderBy('urutan')->get()
            : collect();

        return view('portal.loans.agreement', [
            'role' => $role,
            'loan' => $detail,
            'agreement' => $agreement,
            'snapshot' => $snapshot,
            'attachments' => $attachments,
        ]);
    }

    public function penalties(Request $request, string $role): View
    {
        $this->authorize('collect-installments');
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        // Penalty accrual runs via `loans:refresh-penalties` / scheduler — not on every list load.
        $penaltiesQuery = DB::table('angsuran as i')
            ->join('pinjaman as p', 'p.id', '=', 'i.pinjaman_id')->join('anggota as a', 'a.id', '=', 'p.anggota_id')
            ->whereIn('p.status', \App\Support\LoanLifecycle::collectibleStatuses())->where('i.status', '<>', 'lunas')
            ->when(filled($filters['q'] ?? null), function ($query) use ($filters): void {
                $term = '%'.$filters['q'].'%';
                $query->where(fn ($search) => $search->where('a.nama_lengkap', 'like', $term)->orWhere('a.kode_anggota', 'like', $term)->orWhere('p.kode_pinjaman', 'like', $term));
            })
            ->groupBy('i.id', 'i.pinjaman_id', 'i.angsuran_ke', 'i.keterlambatan_hari', 'i.tanggal_jatuh_tempo', 'i.denda', 'p.kode_pinjaman', 'a.kode_anggota', 'a.nama_lengkap')
            ->select(['i.id', 'i.pinjaman_id', 'i.angsuran_ke', 'i.keterlambatan_hari', 'i.tanggal_jatuh_tempo', 'p.kode_pinjaman', 'a.kode_anggota', 'a.nama_lengkap']);

        if (Schema::hasTable('layanan_bayar_denda')) {
            $penaltiesQuery
                ->leftJoin('layanan_bayar_denda as d', 'd.angsuran_id', '=', 'i.id')
                ->havingRaw('i.denda - COALESCE(SUM(d.jumlah), 0) > 0.005')
                ->selectRaw('i.denda - COALESCE(SUM(d.jumlah), 0) as sisa_denda');
        } else {
            $penaltiesQuery
                ->havingRaw('i.denda > 0.005')
                ->selectRaw('i.denda as sisa_denda');
        }

        $penalties = $penaltiesQuery->orderBy('i.tanggal_jatuh_tempo')->paginate(10)->withQueryString();

        return view('portal.loans.penalties', compact('role', 'penalties', 'filters'));
    }

    public function settlements(Request $request, string $role): View
    {
        $this->authorize('collect-installments');
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $loansQuery = DB::table('pinjaman as p')->join('anggota as a', 'a.id', '=', 'p.anggota_id')
            ->leftJoin('angsuran as i', fn ($join) => $join->on('i.pinjaman_id', '=', 'p.id')->where('i.status', '<>', 'lunas'))
            ->whereIn('p.status', \App\Support\LoanLifecycle::collectibleStatuses())
            ->when(filled($filters['q'] ?? null), function ($query) use ($filters): void {
                $term = '%'.$filters['q'].'%';
                $query->where(fn ($search) => $search->where('a.nama_lengkap', 'like', $term)->orWhere('a.kode_anggota', 'like', $term)->orWhere('p.kode_pinjaman', 'like', $term));
            })
            ->groupBy('p.id', 'p.kode_pinjaman', 'p.sisa_pokok', 'a.kode_anggota', 'a.nama_lengkap')
            ->select(['p.id', 'p.kode_pinjaman', 'p.sisa_pokok', 'a.kode_anggota', 'a.nama_lengkap']);

        if (Schema::hasTable('layanan_bayar_denda')) {
            $loansQuery
                ->leftJoin('layanan_bayar_denda as d', 'd.angsuran_id', '=', 'i.id')
                ->selectRaw('COALESCE(SUM(i.denda), 0) - COALESCE(SUM(d.jumlah), 0) as sisa_denda');
        } else {
            $loansQuery->selectRaw('COALESCE(SUM(i.denda), 0) as sisa_denda');
        }

        $loans = $loansQuery->orderBy('a.nama_lengkap')->paginate(10)->withQueryString();

        return view('portal.loans.settlements', compact('role', 'loans', 'filters'));
    }

    public function receipt(Request $request, string $role, int $loan, int $installment): View
    {
        $this->authorize('view-loans');
        $detail = $this->loanForRole($request, $role, $loan);
        $payment = DB::table('angsuran as a')->where('a.id', $installment)->where('a.pinjaman_id', $loan)
            ->first(['a.*']);
        abort_unless($payment && $payment->status === 'lunas', 404);

        return view('portal.loans.receipt', ['role' => $role, 'loan' => $detail, 'payment' => $payment]);
    }

    public function settlementReceipt(Request $request, string $role, int $loan): View
    {
        $this->authorize('view-loans');
        $detail = $this->loanForRole($request, $role, $loan);
        abort_unless($detail->status === 'lunas', 404);
        $settlement = Schema::hasTable('layanan_pembayaran')
            ? DB::table('layanan_pembayaran')->where('pinjaman_id', $loan)->where('jenis', 'pelunasan')->whereNull('dibatalkan_at')->latest('id')->first()
            : null;
        $penalties = Schema::hasTable('layanan_bayar_denda')
            ? (float) DB::table('layanan_bayar_denda')->where('pinjaman_id', $loan)->sum('jumlah')
            : 0.0;
        $collateral = Schema::hasTable('jaminan_pinjaman')
            ? DB::table('jaminan_pinjaman')->where('pinjaman_id', $loan)->first(['status_jaminan', 'jenis_barang', 'identitas_barang'])
            : null;

        return view('portal.loans.settlement-receipt', compact('role', 'detail', 'settlement', 'penalties', 'collateral'));
    }
}
