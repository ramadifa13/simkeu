<?php

namespace App\Http\Controllers\Portal;

use App\Actions\Audit\RecordActivity;
use App\Actions\Loans\RequestEarlySettlementAction;
use App\Actions\Loans\ReverseSettlementAction;
use App\Actions\Loans\ReviewEarlySettlementAction;
use App\Actions\Loans\SettleLoanAction;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Portal\Concerns\InteractsWithLoans;
use App\Http\Requests\RequestSettlementRequest;
use App\Http\Requests\ReviewSettlementRequest;
use App\Http\Requests\ReverseSettlementRequest;
use App\Http\Requests\SettleLoanRequest;
use App\Support\ImageStorage;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class LoanSettlementController extends Controller
{
    use InteractsWithLoans;

    public function settle(SettleLoanRequest $request, string $role, int $loan, SettleLoanAction $settle, RecordActivity $audit, ImageStorage $images): RedirectResponse
    {
        $detail = $this->loanForRole($request, $role, $loan);
        $takeCollateral = $request->boolean('ambil_jaminan_langsung');
        $summary = $this->settlementSummary($loan);
        $earlyApproved = ($summary['request_status'] ?? null) === 'disetujui';
        $data = $request->validated();
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

    public function requestSettlement(RequestSettlementRequest $request, string $role, int $loan, RequestEarlySettlementAction $requestSettlement, RecordActivity $audit): RedirectResponse
    {
        $this->loanForRole($request, $role, $loan);
        $data = $request->validated();
        try {
            $requestSettlement->handle($loan, $request->user(), $data['catatan'] ?? '');
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['pelunasan' => $e->getMessage()]);
        }
        $audit->handle($request->user(), 'loan.settlement.requested', 'pinjaman', $loan, [], $request);

        return back()->with('status', 'Pengajuan pelunasan dipercepat dikirim ke Admin.');
    }

    public function reviewSettlement(ReviewSettlementRequest $request, string $role, int $loan, ReviewEarlySettlementAction $review, RecordActivity $audit): RedirectResponse
    {
        $this->loanForRole($request, $role, $loan);
        $data = $request->validated();
        try {
            $review->handle($loan, $data['decision'], (float) ($data['diskon'] ?? 0), $request->user(), $data['catatan'] ?? '');
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['pelunasan' => $e->getMessage()]);
        }
        $audit->handle($request->user(), 'loan.settlement.reviewed', 'pinjaman', $loan, ['decision' => $data['decision'], 'diskon' => $data['diskon'] ?? 0], $request);

        return back()->with('status', 'Pengajuan pelunasan '.($data['decision'] === 'disetujui' ? 'disetujui' : 'ditolak').'.');
    }

    public function reverseSettlement(ReverseSettlementRequest $request, string $role, int $loan, ReverseSettlementAction $reverse, RecordActivity $audit): RedirectResponse
    {
        $this->loanForRole($request, $role, $loan);
        $data = $request->validated();
        try {
            $amount = $reverse->handle($loan, $request->user(), $data['alasan']);
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['pembatalan' => $e->getMessage()]);
        }
        $audit->handle($request->user(), 'loan.settlement.reversed', 'pinjaman', $loan, ['nominal' => $amount, 'alasan' => $data['alasan']], $request);

        return back()->with('status', 'Pelunasan dibatalkan melalui mutasi kas pembalik.');
    }
}
