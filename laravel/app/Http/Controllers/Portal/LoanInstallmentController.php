<?php

namespace App\Http\Controllers\Portal;

use App\Actions\Audit\RecordActivity;
use App\Actions\Loans\PayInstallmentAction;
use App\Actions\Loans\PayLoanPenaltyAction;
use App\Actions\Loans\RefreshLoanPenaltiesAction;
use App\Actions\Loans\RequestInstallmentReversalAction;
use App\Actions\Loans\ReverseInstallmentPaymentAction;
use App\Actions\Loans\ReviewInstallmentReversalRequestAction;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Portal\Concerns\InteractsWithLoans;
use App\Http\Requests\PayInstallmentRequest;
use App\Support\ImageStorage;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LoanInstallmentController extends Controller
{
    use InteractsWithLoans;

    public function pay(PayInstallmentRequest $request, string $role, int $loan, int $installment, RefreshLoanPenaltiesAction $refresh, PayInstallmentAction $pay, RecordActivity $audit, ImageStorage $images): RedirectResponse
    {
        $this->loanForRole($request, $role, $loan);
        $row = DB::table('angsuran')->where('id', $installment)->where('pinjaman_id', $loan)->first();
        abort_unless($row, 404);
        $data = $request->validated();
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

    public function reverseInstallment(Request $request, string $role, int $loan, int $installment, ReverseInstallmentPaymentAction $reverse, RecordActivity $audit): RedirectResponse
    {
        $this->authorize('reverse-installment-payment');
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
        $this->authorize('request-installment-reversal');
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
        $this->authorize('decide-installment-reversal');
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
}
