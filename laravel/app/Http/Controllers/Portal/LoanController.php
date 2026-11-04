<?php

namespace App\Http\Controllers\Portal;

use App\Actions\Audit\RecordActivity;
use App\Actions\Loans\CheckoutInstallmentCartAction;
use App\Actions\Loans\CompleteDisbursementPhotosAction;
use App\Actions\Loans\MarkInstallmentReversalSeenAction;
use App\Actions\Loans\PayInstallmentAction;
use App\Actions\Loans\PayLoanPenaltyAction;
use App\Actions\Loans\RefreshLoanPenaltiesAction;
use App\Actions\Loans\RequestCollateralReturnAction;
use App\Actions\Loans\RequestEarlySettlementAction;
use App\Actions\Loans\RequestInstallmentReversalAction;
use App\Actions\Loans\ReturnCollateralAction;
use App\Actions\Loans\ReverseInstallmentPaymentAction;
use App\Actions\Loans\ReverseSettlementAction;
use App\Actions\Loans\ReviewCollateralReturnAction;
use App\Actions\Loans\ReviewEarlySettlementAction;
use App\Actions\Loans\ReviewInstallmentReversalRequestAction;
use App\Actions\Loans\SettleLoanAction;
use App\Http\Controllers\Controller;
use App\Models\Anggota;
use App\Support\ImageStorage;
use Carbon\Carbon;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LoanController extends Controller
{
    private const CART_KEY = 'loan_installment_cart';

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

        return view('portal.loans.index', [
            'role' => $role,
            'filters' => $filters,
            'loans' => $query->orderByDesc('p.tanggal_pencairan')->paginate(10)->withQueryString(),
        ]);
    }

    public function show(Request $request, string $role, int $loan, RefreshLoanPenaltiesAction $refresh, MarkInstallmentReversalSeenAction $markReversalSeen): View
    {
        $this->authorize('view-loans');
        $detail = $this->loanForRole($request, $role, $loan);
        if ($detail->status === 'aktif') {
            $refresh->handle($loan, today()->toDateString());
            $detail = $this->loanForRole($request, $role, $loan);
        }

        if ($role === 'teller') {
            $markReversalSeen->handle($request->user(), $loan);
        }

        $installmentQuery = DB::table('angsuran as a')->where('a.pinjaman_id', $loan)->orderBy('a.angsuran_ke')->select('a.*');
        if (Schema::hasTable('layanan_bayar_denda')) {
            $installmentQuery->selectSub(
                DB::table('layanan_bayar_denda')->selectRaw('COALESCE(SUM(jumlah), 0)')->whereColumn('angsuran_id', 'a.id'),
                'denda_dibayar'
            );
        }
        $installments = $installmentQuery->get();
        if (! Schema::hasTable('layanan_bayar_denda')) {
            $installments->each(fn (object $row) => $row->denda_dibayar = 0);
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

        $cartItems = collect($request->session()->get(self::CART_KEY, []));
        $cartIds = $cartItems->pluck('installment_id')->map(fn ($id) => (int) $id)->all();
        $cartOnThisLoan = $installments->whereIn('id', $cartIds)->values();

        return view('portal.loans.show', [
            'role' => $role,
            'loan' => $detail,
            'installments' => $installments,
            'reversalRequests' => $reversalRequests,
            'settlement' => $this->settlementSummary($loan),
            'collateral' => DB::table('jaminan_pinjaman')->where('pinjaman_id', $loan)->first(),
            'collateralRequest' => $collateralRequest,
            'agreement' => $agreement,
            'needsDisbursementPhoto' => $needsDisbursementPhoto,
            'cartIds' => $cartIds,
            'cartCount' => count($cartIds),
            'cartLabels' => $cartOnThisLoan->pluck('angsuran_ke')->all(),
            'cartOnThisLoanCount' => $cartOnThisLoan->count(),
        ]);
    }

    public function completeDisbursementPhotos(Request $request, string $role, int $loan, CompleteDisbursementPhotosAction $complete, RecordActivity $audit, ImageStorage $images): RedirectResponse
    {
        $this->authorize('disburse-loans');
        $detail = $this->loanForRole($request, $role, $loan);
        $needsCollateral = (bool) $detail->memerlukan_jaminan;
        $data = $request->validate([
            'foto_nasabah' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'foto_barang_jaminan' => [$needsCollateral ? 'nullable' : 'prohibited', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'foto_nasabah_barang' => [$needsCollateral ? 'nullable' : 'prohibited', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ]);

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
        $penalties = DB::table('angsuran as i')
            ->join('pinjaman as p', 'p.id', '=', 'i.pinjaman_id')->join('anggota as a', 'a.id', '=', 'p.anggota_id')
            ->leftJoin('layanan_bayar_denda as d', 'd.angsuran_id', '=', 'i.id')
            ->where('p.status', 'aktif')->where('i.status', '<>', 'lunas')
            ->when(filled($filters['q'] ?? null), function ($query) use ($filters): void {
                $term = '%'.$filters['q'].'%';
                $query->where(fn ($search) => $search->where('a.nama_lengkap', 'like', $term)->orWhere('a.kode_anggota', 'like', $term)->orWhere('p.kode_pinjaman', 'like', $term));
            })
            ->groupBy('i.id', 'i.pinjaman_id', 'i.angsuran_ke', 'i.keterlambatan_hari', 'i.tanggal_jatuh_tempo', 'i.denda', 'p.kode_pinjaman', 'a.kode_anggota', 'a.nama_lengkap')
            ->havingRaw('i.denda - COALESCE(SUM(d.jumlah), 0) > 0.005')
            ->select(['i.id', 'i.pinjaman_id', 'i.angsuran_ke', 'i.keterlambatan_hari', 'i.tanggal_jatuh_tempo', 'p.kode_pinjaman', 'a.kode_anggota', 'a.nama_lengkap'])
            ->selectRaw('i.denda - COALESCE(SUM(d.jumlah), 0) as sisa_denda')
            ->orderBy('i.tanggal_jatuh_tempo')->paginate(10)->withQueryString();

        return view('portal.loans.penalties', compact('role', 'penalties', 'filters'));
    }

    public function settlements(Request $request, string $role): View
    {
        $this->authorize('collect-installments');
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $loans = DB::table('pinjaman as p')->join('anggota as a', 'a.id', '=', 'p.anggota_id')
            ->leftJoin('angsuran as i', fn ($join) => $join->on('i.pinjaman_id', '=', 'p.id')->where('i.status', '<>', 'lunas'))
            ->leftJoin('layanan_bayar_denda as d', 'd.angsuran_id', '=', 'i.id')
            ->where('p.status', 'aktif')
            ->when(filled($filters['q'] ?? null), function ($query) use ($filters): void {
                $term = '%'.$filters['q'].'%';
                $query->where(fn ($search) => $search->where('a.nama_lengkap', 'like', $term)->orWhere('a.kode_anggota', 'like', $term)->orWhere('p.kode_pinjaman', 'like', $term));
            })
            ->groupBy('p.id', 'p.kode_pinjaman', 'p.sisa_pokok', 'a.kode_anggota', 'a.nama_lengkap')
            ->select(['p.id', 'p.kode_pinjaman', 'p.sisa_pokok', 'a.kode_anggota', 'a.nama_lengkap'])
            ->selectRaw('COALESCE(SUM(i.denda), 0) - COALESCE(SUM(d.jumlah), 0) as sisa_denda')
            ->orderBy('a.nama_lengkap')->paginate(10)->withQueryString();

        return view('portal.loans.settlements', compact('role', 'loans', 'filters'));
    }

    public function pay(Request $request, string $role, int $loan, int $installment, RefreshLoanPenaltiesAction $refresh, PayInstallmentAction $pay, RecordActivity $audit, ImageStorage $images): RedirectResponse
    {
        $this->authorize('collect-installments');
        $this->loanForRole($request, $role, $loan);
        $row = DB::table('angsuran')->where('id', $installment)->where('pinjaman_id', $loan)->first();
        abort_unless($row, 404);
        $data = $request->validate([
            'tanggal_bayar' => ['required', 'date', 'before_or_equal:today'],
            'metode' => ['required', Rule::in(['cash', 'transfer'])],
            'denda' => ['nullable', 'numeric', 'min:0'],
            'bukti' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ]);
        $refresh->handle($loan, $data['tanggal_bayar']);
        $this->assertInstallmentMonthOpen($data['tanggal_bayar']);
        $manual = array_key_exists('denda', $data) && $data['denda'] !== null ? (float) $data['denda'] : null;

        try {
            $proof = $request->hasFile('bukti') ? $images->store($request->file('bukti'), 'pembayaran') : '';
            $result = $pay->handle($installment, $data['tanggal_bayar'], $data['metode'], $request->user(), $manual, $proof);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['pembayaran' => $exception->getMessage()]);
        }

        $audit->handle($request->user(), 'loan.installment.paid', 'angsuran', $installment, [
            'pinjaman_id' => $loan,
            'nominal' => $result['total'],
            'metode' => $data['metode'],
            'tanggal_bayar' => $data['tanggal_bayar'],
        ], $request);

        return redirect()->route('portal.loans.receipt', ['role' => $role, 'loan' => $loan, 'installment' => $installment])
            ->with('status', 'Pembayaran angsuran berhasil dicatat.');
    }

    public function addToCart(Request $request, string $role, int $loan, int $installment): RedirectResponse
    {
        $this->authorize('collect-installments');
        $this->loanForRole($request, $role, $loan);
        abort_unless(DB::table('angsuran')->where('id', $installment)->where('pinjaman_id', $loan)->where('status', '<>', 'lunas')->exists(), 404);
        $cart = $this->cartItems($request);
        $cart[$installment] = $this->normalizeCartItem($loan, $installment, $cart[$installment] ?? []);
        $request->session()->put(self::CART_KEY, $cart);

        return back()->with('status', 'Angsuran ditambahkan ke keranjang.');
    }

    public function bulkAddToCart(Request $request, string $role, int $loan): RedirectResponse
    {
        $this->authorize('collect-installments');
        $this->loanForRole($request, $role, $loan);
        $data = $request->validate([
            'installment_ids' => ['required', 'array', 'min:1'],
            'installment_ids.*' => ['integer', 'distinct'],
        ]);

        $validIds = DB::table('angsuran')
            ->where('pinjaman_id', $loan)
            ->where('status', '<>', 'lunas')
            ->whereIn('id', $data['installment_ids'])
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($validIds === []) {
            throw ValidationException::withMessages(['installment_ids' => 'Tidak ada angsuran valid untuk dimasukkan ke keranjang.']);
        }

        $cart = $this->cartItems($request);
        foreach ($validIds as $installmentId) {
            $cart[$installmentId] = $this->normalizeCartItem($loan, $installmentId, $cart[$installmentId] ?? []);
        }
        $request->session()->put(self::CART_KEY, $cart);

        return back()->with('status', count($validIds).' angsuran ditambahkan ke keranjang.');
    }

    public function removeFromCart(Request $request, string $role, int $installment): RedirectResponse
    {
        $this->authorize('collect-installments');
        $cart = $this->cartItems($request);
        unset($cart[$installment]);
        $request->session()->put(self::CART_KEY, $cart);

        return back()->with('status', 'Angsuran dihapus dari keranjang.');
    }

    public function clearCart(Request $request, string $role): RedirectResponse
    {
        $this->authorize('collect-installments');
        $request->session()->forget(self::CART_KEY);

        return redirect()
            ->route('portal.loans.cart', ['role' => $role])
            ->with('status', 'Keranjang dikosongkan.');
    }

    public function cart(Request $request, string $role): View
    {
        $this->authorize('collect-installments');
        [$rows, $cart] = $this->cartRows($request);
        $request->session()->put(self::CART_KEY, $cart);

        $fromLoan = $request->integer('from') ?: null;
        if ($fromLoan && ! $rows->contains(fn ($row) => (int) $row->pinjaman_id === $fromLoan)) {
            $fromLoan = $rows->first()?->pinjaman_id;
        } elseif (! $fromLoan) {
            $fromLoan = $rows->first()?->pinjaman_id;
        }

        return view('portal.loans.cart', [
            'role' => $role,
            'rows' => $rows,
            'cart' => $cart,
            'fromLoan' => $fromLoan ? (int) $fromLoan : null,
            'defaultDate' => today()->toDateString(),
            'defaultMethod' => 'cash',
        ]);
    }

    public function updateCart(Request $request, string $role): RedirectResponse
    {
        $this->authorize('collect-installments');
        $cart = $this->persistCartOverrides($request);

        return redirect()
            ->route('portal.loans.cart', array_filter(['role' => $role, 'from' => $request->integer('from') ?: null]))
            ->with('status', count($cart).' item keranjang disimpan.');
    }

    public function checkoutCart(Request $request, string $role, CheckoutInstallmentCartAction $checkout, RecordActivity $audit, RefreshLoanPenaltiesAction $refresh): RedirectResponse
    {
        $this->authorize('collect-installments');
        $cart = $this->persistCartOverrides($request);
        if ($cart === []) {
            throw ValidationException::withMessages(['keranjang' => 'Keranjang angsuran kosong.']);
        }

        $payload = [];
        $loanIds = [];
        foreach ($cart as $item) {
            $date = (string) ($item['tanggal_bayar'] ?: today()->toDateString());
            $method = (string) ($item['metode'] ?: 'cash');
            $this->assertInstallmentMonthOpen($date);
            $loanIds[(int) $item['loan_id']] = true;
            $payload[] = [
                'installment_id' => (int) $item['installment_id'],
                'tanggal_bayar' => $date,
                'metode' => $method,
                'denda' => $item['denda'],
            ];
        }

        foreach (array_keys($loanIds) as $loanId) {
            $refresh->handle((int) $loanId, today()->toDateString());
        }

        try {
            $result = $checkout->handle($payload, $request->user());
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['keranjang' => $e->getMessage()]);
        }

        $request->session()->forget(self::CART_KEY);
        $audit->handle($request->user(), 'loan.installment.cart.checked_out', 'angsuran', null, [
            'jumlah_item' => count($result),
            'installment_ids' => array_column($payload, 'installment_id'),
        ], $request);

        $redirectLoan = collect($result)->first()['loan_id'] ?? null;

        return redirect()
            ->route(
                $redirectLoan ? 'portal.loans.show' : 'portal.loans.index',
                $redirectLoan
                    ? ['role' => $role, 'loan' => $redirectLoan]
                    : ['role' => $role, 'status' => 'aktif']
            )
            ->with('status', count($result).' angsuran berhasil dibayar dari keranjang.');
    }

    public function payPenalty(Request $request, string $role, int $loan, int $installment, RefreshLoanPenaltiesAction $refresh, PayLoanPenaltyAction $pay, RecordActivity $audit, ImageStorage $images): RedirectResponse
    {
        $this->authorize('collect-installments');
        $this->loanForRole($request, $role, $loan);
        $row = DB::table('angsuran')->where('id', $installment)->where('pinjaman_id', $loan)->first();
        abort_unless($row, 404);
        $data = $request->validate(['tanggal_bayar' => ['required', 'date', 'before_or_equal:today'], 'metode' => ['required', Rule::in(['cash', 'transfer'])], 'jumlah' => ['required', 'numeric', 'gt:0'], 'bukti' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240']]);
        $refresh->handle($loan, $data['tanggal_bayar']);
        try {
            $proof = $images->store($request->file('bukti'), 'pembayaran');
            $result = $pay->handle($installment, (float) $data['jumlah'], $data['metode'], $data['tanggal_bayar'], $request->user(), $proof);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['denda' => $exception->getMessage()]);
        }
        $audit->handle($request->user(), 'loan.penalty.paid', 'angsuran', $installment, ['pinjaman_id' => $loan, 'nominal' => $result['total'], 'metode' => $data['metode']], $request);

        return back()->with('status', 'Pembayaran denda berhasil dicatat. Sisa denda: Rp '.number_format($result['remaining'], 0, ',', '.').'.');
    }

    public function settle(Request $request, string $role, int $loan, SettleLoanAction $settle, RecordActivity $audit, ImageStorage $images): RedirectResponse
    {
        $this->authorize('collect-installments');
        $detail = $this->loanForRole($request, $role, $loan);
        $takeCollateral = $request->boolean('ambil_jaminan_langsung');
        $summary = $this->settlementSummary($loan);
        $earlyApproved = ($summary['request_status'] ?? null) === 'disetujui';
        $requiresPhysicalCheck = $earlyApproved && (bool) $detail->memerlukan_jaminan;
        $data = $request->validate([
            'tanggal_bayar' => ['required', 'date', 'before_or_equal:today'],
            'metode' => ['required', Rule::in(['cash', 'transfer'])],
            'catatan' => ['nullable', 'string', 'max:1000'],
            'bukti' => [$earlyApproved ? 'required' : 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'cek_fisik' => [$requiresPhysicalCheck ? 'accepted' : 'nullable'],
            'ambil_jaminan_langsung' => ['nullable', 'boolean'],
            'cek_jaminan_nasabah' => [$takeCollateral ? 'accepted' : 'nullable'],
            'foto_serah_terima_jaminan' => [$takeCollateral ? 'required' : 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'ttd_jaminan_nasabah' => [$takeCollateral ? 'required' : 'nullable', 'image', 'mimes:webp', 'max:1024'],
            'ttd_jaminan_teller' => [$takeCollateral ? 'required' : 'nullable', 'image', 'mimes:webp', 'max:1024'],
            'mode_cetak_akad' => [$takeCollateral ? 'required' : 'nullable', Rule::in(['otomatis', 'manual'])],
        ]);
        if ($takeCollateral && ! (bool) $detail->memerlukan_jaminan) {
            throw ValidationException::withMessages(['ambil_jaminan_langsung' => 'Pengambilan jaminan langsung hanya untuk pinjaman berjaminan.']);
        }
        if ($earlyApproved) {
            $takeCollateral = false;
        }
        $stored = [];
        try {
            $proof = $request->hasFile('bukti') ? $images->store($request->file('bukti'), 'pembayaran') : '';
            $immediate = null;
            if ($takeCollateral) {
                $stored['foto'] = $images->store($request->file('foto_serah_terima_jaminan'), 'jaminan_kembali');
                $stored['ttd'] = $images->store($request->file('ttd_jaminan_nasabah'), 'jaminan_kembali/tanda-tangan');
                $stored['ttd_teller'] = $images->store($request->file('ttd_jaminan_teller'), 'jaminan_kembali/tanda-tangan');
                $immediate = $stored;
            }
            $result = $settle->handle($loan, $data['tanggal_bayar'], $data['metode'], $request->user(), $summary['discount_percent'], $data['catatan'] ?? '', $proof, $immediate);
        } catch (DomainException $exception) {
            foreach ($stored as $path) {
                Storage::disk('local')->delete($path);
            }
            throw ValidationException::withMessages(['pelunasan' => $exception->getMessage()]);
        }
        $audit->handle($request->user(), 'loan.settled', 'pinjaman', $loan, ['nominal' => $result['total'], 'diskon_bunga' => $result['discount'], 'metode' => $data['metode'], 'ambil_jaminan' => $takeCollateral], $request);

        if ($takeCollateral && ($data['mode_cetak_akad'] ?? 'manual') === 'otomatis') {
            return redirect()
                ->route('portal.loans.settlement.receipt', ['role' => $role, 'loan' => $loan])
                ->with('status', 'Pelunasan berhasil dicatat dan jaminan diserahkan.');
        }

        return redirect()->route('portal.loans.show', ['role' => $role, 'loan' => $loan])->with('status', 'Pelunasan berhasil dicatat'.($takeCollateral ? ' dan jaminan diserahkan.' : '.'));
    }

    public function requestSettlement(Request $request, string $role, int $loan, RequestEarlySettlementAction $requestSettlement, RecordActivity $audit): RedirectResponse
    {
        $this->authorize('collect-installments');
        $this->loanForRole($request, $role, $loan);
        $data = $request->validate(['catatan' => ['nullable', 'string', 'max:1000']]);
        try {
            $requestSettlement->handle($loan, $request->user(), $data['catatan'] ?? '');
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['pelunasan' => $e->getMessage()]);
        }
        $audit->handle($request->user(), 'loan.settlement.requested', 'pinjaman', $loan, [], $request);

        return back()->with('status', 'Pengajuan pelunasan dipercepat dikirim ke Admin.');
    }

    public function reviewSettlement(Request $request, string $role, int $loan, ReviewEarlySettlementAction $review, RecordActivity $audit): RedirectResponse
    {
        $this->authorize('review-loan-applications');
        $this->loanForRole($request, $role, $loan);
        $data = $request->validate(['decision' => ['required', Rule::in(['disetujui', 'ditolak'])], 'diskon' => ['nullable', 'numeric', 'min:0', 'max:100'], 'catatan' => ['nullable', 'string', 'max:1000']]);
        try {
            $review->handle($loan, $data['decision'], (float) ($data['diskon'] ?? 0), $request->user(), $data['catatan'] ?? '');
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['pelunasan' => $e->getMessage()]);
        }
        $audit->handle($request->user(), 'loan.settlement.reviewed', 'pinjaman', $loan, ['decision' => $data['decision'], 'diskon' => $data['diskon'] ?? 0], $request);

        return back()->with('status', 'Pengajuan pelunasan '.($data['decision'] === 'disetujui' ? 'disetujui' : 'ditolak').'.');
    }

    public function requestCollateralReturn(Request $request, string $role, int $loan, RequestCollateralReturnAction $requestReturn, RecordActivity $audit, ImageStorage $images): RedirectResponse
    {
        $this->authorize('collect-installments');
        $this->loanForRole($request, $role, $loan);
        $data = $request->validate([
            'alasan' => ['required', 'string', 'min:5', 'max:2000'],
            'bukti_jaminan' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'bukti_lunas' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ]);
        $collateralProof = $images->store($request->file('bukti_jaminan'), 'jaminan_kembali');
        $settlementProof = $images->store($request->file('bukti_lunas'), 'jaminan_kembali');
        try {
            $requestReturn->handle($loan, $request->user(), $data['alasan'], $collateralProof, $settlementProof);
        } catch (DomainException $e) {
            Storage::disk('local')->delete([$collateralProof, $settlementProof]);
            throw ValidationException::withMessages(['jaminan' => $e->getMessage()]);
        }
        $audit->handle($request->user(), 'loan.collateral.return_requested', 'pinjaman', $loan, [], $request);

        return back()->with('status', 'Pengajuan pengembalian jaminan dikirim ke Admin.');
    }

    public function reviewCollateralReturn(Request $request, string $role, int $loan, ReviewCollateralReturnAction $review, RecordActivity $audit): RedirectResponse
    {
        $this->authorize('review-collateral-return');
        $this->loanForRole($request, $role, $loan);
        $data = $request->validate([
            'decision' => ['required', Rule::in(['pengecekan', 'disetujui', 'ditolak'])],
            'catatan_admin' => ['required', 'string', 'min:5', 'max:2000'],
        ]);
        try {
            $review->handle($loan, $data['decision'], $request->user(), $data['catatan_admin']);
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['jaminan' => $e->getMessage()]);
        }
        $audit->handle($request->user(), 'loan.collateral.return_reviewed', 'pinjaman', $loan, ['decision' => $data['decision']], $request);
        $message = match ($data['decision']) {
            'pengecekan' => 'Pengajuan pengembalian jaminan ditandai sedang dicek.',
            'disetujui' => 'Pengajuan pengembalian jaminan disetujui.',
            default => 'Pengajuan pengembalian jaminan ditolak.',
        };

        return back()->with('status', $message);
    }

    public function returnCollateral(Request $request, string $role, int $loan, ReturnCollateralAction $returnCollateral, RecordActivity $audit, ImageStorage $images): RedirectResponse
    {
        $this->authorize('collect-installments');
        $this->loanForRole($request, $role, $loan);
        $data = $request->validate([
            'foto' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'ttd_nasabah' => ['required', 'image', 'mimes:webp', 'max:1024'],
            'ttd_teller' => ['required', 'image', 'mimes:webp', 'max:1024'],
            'cek_fisik' => ['accepted'],
            'catatan' => ['nullable', 'string', 'max:1000'],
        ]);
        $photo = $images->store($request->file('foto'), 'jaminan_kembali');
        $memberSignature = $images->store($request->file('ttd_nasabah'), 'jaminan_kembali/tanda-tangan');
        $tellerSignature = $images->store($request->file('ttd_teller'), 'jaminan_kembali/tanda-tangan');
        try {
            $returnCollateral->handle($loan, $request->user(), $photo, $memberSignature, $tellerSignature, $data['catatan'] ?? '', true);
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['jaminan' => $e->getMessage()]);
        }
        $audit->handle($request->user(), 'loan.collateral.returned', 'pinjaman', $loan, [], $request);

        return back()->with('status', 'Serah terima jaminan berhasil dicatat.');
    }

    public function reverseInstallment(Request $request, string $role, int $loan, int $installment, ReverseInstallmentPaymentAction $reverse, RecordActivity $audit): RedirectResponse
    {
        $this->authorize('review-loan-applications');
        $this->loanForRole($request, $role, $loan);
        $data = $request->validate(['alasan' => ['required', 'string', 'min:5', 'max:250']]);
        $installmentRow = DB::table('angsuran')->where('id', $installment)->where('pinjaman_id', $loan)->first(['tanggal_bayar']);
        abort_unless($installmentRow, 404);
        if ($installmentRow->tanggal_bayar) {
            $this->assertInstallmentMonthOpen($installmentRow->tanggal_bayar);
        }
        try {
            $result = $reverse->handle($installment, $request->user(), $data['alasan']);
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['pembatalan' => $e->getMessage()]);
        }
        $audit->handle($request->user(), 'loan.installment.reversed', 'angsuran', $installment, ['pinjaman_id' => $result['loan_id'], 'nominal' => $result['amount'], 'alasan' => $data['alasan']], $request);

        return back()->with('status', 'Pembayaran angsuran dibatalkan melalui mutasi pembalik.');
    }

    public function requestInstallmentReversal(Request $request, string $role, int $loan, int $installment, RequestInstallmentReversalAction $reversal, RecordActivity $audit): RedirectResponse
    {
        $this->authorize('collect-installments');
        $this->loanForRole($request, $role, $loan);
        $data = $request->validate(['alasan' => ['required', 'string', 'min:5', 'max:250']]);
        try {
            $reversal->handle($loan, $installment, $request->user(), $data['alasan']);
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['pembatalan' => $e->getMessage()]);
        }
        $audit->handle($request->user(), 'loan.installment.reversal_requested', 'angsuran', $installment, ['pinjaman_id' => $loan, 'alasan' => $data['alasan']], $request);

        return back()->with('status', 'Pengajuan pembatalan dikirim dan menunggu keputusan Admin.');
    }

    public function reviewInstallmentReversalRequest(Request $request, string $role, int $loan, int $reversalRequest, ReviewInstallmentReversalRequestAction $review, RecordActivity $audit): RedirectResponse
    {
        $this->authorize('review-loan-applications');
        $this->loanForRole($request, $role, $loan);
        $data = $request->validate(['decision' => ['required', Rule::in(['disetujui', 'ditolak'])], 'catatan' => ['nullable', 'string', 'max:500']]);
        if ($data['decision'] === 'disetujui') {
            $paidAt = DB::table('pengajuan_pembatalan_angsuran as r')->join('angsuran as a', 'a.id', '=', 'r.angsuran_id')->where('r.id', $reversalRequest)->where('a.pinjaman_id', $loan)->value('a.tanggal_bayar');
            if ($paidAt) {
                $this->assertInstallmentMonthOpen($paidAt);
            }
        }
        try {
            $result = $review->handle($loan, $reversalRequest, $data['decision'], $request->user(), $data['catatan'] ?? '');
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['pembatalan' => $e->getMessage()]);
        }
        $audit->handle($request->user(), 'loan.installment.reversal_reviewed', 'pengajuan_pembatalan_angsuran', $reversalRequest, ['pinjaman_id' => $loan, 'keputusan' => $data['decision'], 'nominal' => $result['amount']], $request);

        return back()->with('status', $data['decision'] === 'disetujui' ? 'Pembatalan disetujui dan mutasi kas pembalik dibuat.' : 'Pengajuan pembatalan ditolak.');
    }

    public function reverseSettlement(Request $request, string $role, int $loan, ReverseSettlementAction $reverse, RecordActivity $audit): RedirectResponse
    {
        $this->authorize('review-loan-applications');
        $this->loanForRole($request, $role, $loan);
        $data = $request->validate(['alasan' => ['required', 'string', 'min:5', 'max:250']]);
        try {
            $amount = $reverse->handle($loan, $request->user(), $data['alasan']);
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['pembatalan' => $e->getMessage()]);
        }
        $audit->handle($request->user(), 'loan.settlement.reversed', 'pinjaman', $loan, ['nominal' => $amount, 'alasan' => $data['alasan']], $request);

        return back()->with('status', 'Pelunasan dibatalkan melalui mutasi kas pembalik.');
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
        $settlement = DB::table('layanan_pembayaran')->where('pinjaman_id', $loan)->where('jenis', 'pelunasan')->whereNull('dibatalkan_at')->latest('id')->first();
        $penalties = DB::table('layanan_bayar_denda')->where('pinjaman_id', $loan)->sum('jumlah');
        $collateral = DB::table('jaminan_pinjaman')->where('pinjaman_id', $loan)->first(['status_jaminan', 'jenis_barang', 'identitas_barang']);

        return view('portal.loans.settlement-receipt', compact('role', 'detail', 'settlement', 'penalties', 'collateral'));
    }

    /** @return array<int, array{loan_id:int,installment_id:int,tanggal_bayar:?string,metode:?string,denda:?float}> */
    private function cartItems(Request $request): array
    {
        $raw = $request->session()->get(self::CART_KEY, []);
        $cart = [];
        foreach ($raw as $key => $item) {
            $installmentId = (int) ($item['installment_id'] ?? $key);
            $loanId = (int) ($item['loan_id'] ?? 0);
            if ($installmentId <= 0 || $loanId <= 0) {
                continue;
            }
            $cart[$installmentId] = $this->normalizeCartItem($loanId, $installmentId, is_array($item) ? $item : []);
        }

        return $cart;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{loan_id:int,installment_id:int,tanggal_bayar:?string,metode:?string,denda:?float}
     */
    private function normalizeCartItem(int $loanId, int $installmentId, array $item): array
    {
        $method = $item['metode'] ?? null;
        $method = in_array($method, ['cash', 'transfer'], true) ? $method : null;
        $denda = $item['denda'] ?? null;

        return [
            'loan_id' => $loanId,
            'installment_id' => $installmentId,
            'tanggal_bayar' => filled($item['tanggal_bayar'] ?? null) ? (string) $item['tanggal_bayar'] : null,
            'metode' => $method,
            'denda' => $denda === null || $denda === '' ? null : (float) $denda,
        ];
    }

    /**
     * @return array{0:Collection<int, object>, 1: array<int, array{loan_id:int,installment_id:int,tanggal_bayar:?string,metode:?string,denda:?float}>}
     */
    private function cartRows(Request $request): array
    {
        $cart = $this->cartItems($request);
        $ids = array_keys($cart);
        if ($ids === []) {
            return [collect(), []];
        }

        $query = DB::table('angsuran as i')
            ->join('pinjaman as p', 'p.id', '=', 'i.pinjaman_id')
            ->join('anggota as a', 'a.id', '=', 'p.anggota_id')
            ->whereIn('i.id', $ids)
            ->where('i.status', '<>', 'lunas')
            ->select('i.*', 'p.kode_pinjaman', 'a.nama_lengkap')
            ->orderBy('a.nama_lengkap')
            ->orderBy('i.angsuran_ke');

        if (Schema::hasTable('layanan_bayar_denda')) {
            $query->selectSub(
                DB::table('layanan_bayar_denda')->selectRaw('COALESCE(SUM(jumlah), 0)')->whereColumn('angsuran_id', 'i.id'),
                'denda_dibayar'
            );
        }

        $rows = $query->get();
        if (! Schema::hasTable('layanan_bayar_denda')) {
            $rows->each(function (object $row): void {
                $row->denda_dibayar = 0;
            });
        }

        $validIds = $rows->pluck('id')->map(fn ($id) => (int) $id)->all();
        $cart = array_intersect_key($cart, array_flip($validIds));

        return [$rows, $cart];
    }

    /** @return array<int, array{loan_id:int,installment_id:int,tanggal_bayar:?string,metode:?string,denda:?float}> */
    private function persistCartOverrides(Request $request): array
    {
        $cart = $this->cartItems($request);
        if ($cart === []) {
            return [];
        }

        $rules = [
            'from' => ['nullable', 'integer'],
            'default_tanggal_bayar' => ['nullable', 'date', 'before_or_equal:today'],
            'default_metode' => ['nullable', Rule::in(['cash', 'transfer'])],
            'items' => ['nullable', 'array'],
            'items.*.tanggal_bayar' => ['nullable', 'date', 'before_or_equal:today'],
            'items.*.metode' => ['nullable', Rule::in(['cash', 'transfer'])],
        ];
        if ($request->user()?->role === 'admin') {
            $rules['items.*.denda'] = ['nullable', 'numeric', 'min:0'];
        }

        $data = $request->validate($rules);
        $defaultDate = $data['default_tanggal_bayar'] ?? today()->toDateString();
        $defaultMethod = $data['default_metode'] ?? 'cash';
        $items = $data['items'] ?? [];

        foreach ($cart as $installmentId => $item) {
            $override = $items[$installmentId] ?? [];
            $cart[$installmentId] = $this->normalizeCartItem(
                (int) $item['loan_id'],
                (int) $installmentId,
                [
                    'tanggal_bayar' => $override['tanggal_bayar'] ?? $item['tanggal_bayar'] ?? $defaultDate,
                    'metode' => $override['metode'] ?? $item['metode'] ?? $defaultMethod,
                    'denda' => array_key_exists('denda', $override)
                        ? $override['denda']
                        : ($item['denda'] ?? null),
                ]
            );
        }

        $request->session()->put(self::CART_KEY, $cart);

        return $cart;
    }

    private function loanForRole(Request $request, string $role, int $loan): object
    {
        $query = DB::table('pinjaman as p')->join('anggota as a', 'a.id', '=', 'p.anggota_id')
            ->join('jenis_pinjaman as jp', 'jp.id', '=', 'p.jenis_pinjaman_id')
            ->where('p.id', $loan)
            ->select(['p.*', 'a.kode_anggota', 'a.nama_lengkap', 'jp.nama_jenis', 'jp.memerlukan_jaminan']);
        if ($role === 'anggota') {
            $query->where('p.anggota_id', Anggota::query()->where('user_id', $request->user()->id)->value('id'));
        }
        $result = $query->first();
        abort_unless($result, 404);

        return $result;
    }

    private function assertInstallmentMonthOpen(string $date): void
    {
        if (! Schema::hasTable('closing_angsuran_bulanan')) {
            return;
        }
        $month = Carbon::parse($date)->format('Y-m');
        if (DB::table('closing_angsuran_bulanan')->where('bulan', $month)->where('status', 'terkunci')->exists()) {
            throw ValidationException::withMessages(['tanggal_bayar' => 'Periode angsuran '.$month.' sudah dikunci.']);
        }
    }

    /** @return array{gross:float,discount_percent:float,discount_amount:float,total:float,penalty:float,request_status:?string} */
    private function settlementSummary(int $loan): array
    {
        $rows = DB::table('angsuran as i')->where('i.pinjaman_id', $loan)->where('i.status', '<>', 'lunas')
            ->select('i.*')
            ->selectSub(DB::table('layanan_bayar_denda')->selectRaw('COALESCE(SUM(jumlah), 0)')->whereColumn('angsuran_id', 'i.id'), 'denda_dibayar')->get();
        $discount = 0.0;
        $requestStatus = null;
        if (Schema::hasTable('layanan_pelunasan')) {
            $settlementRequest = DB::table('layanan_pelunasan')->where('pinjaman_id', $loan)->first(['status', 'diskon_persen']);
            $requestStatus = $settlementRequest?->status;
            $discount = $requestStatus === 'disetujui' ? (float) $settlementRequest->diskon_persen : 0.0;
        }
        $gross = (float) $rows->sum('jumlah_angsuran');
        $discountAmount = round((float) $rows->sum('bunga') * $discount / 100, 2);

        return ['gross' => $gross, 'discount_percent' => $discount, 'discount_amount' => $discountAmount, 'total' => max(0, $gross - $discountAmount), 'penalty' => (float) $rows->sum(fn ($row) => max(0, (float) $row->denda - (float) $row->denda_dibayar)), 'request_status' => $requestStatus];
    }
}
