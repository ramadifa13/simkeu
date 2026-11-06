<?php

namespace App\Http\Controllers\Portal;

use App\Actions\Audit\RecordActivity;
use App\Actions\Loans\RequestCollateralReturnAction;
use App\Actions\Loans\ReturnCollateralAction;
use App\Actions\Loans\ReviewCollateralReturnAction;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Portal\Concerns\InteractsWithLoans;
use App\Support\ImageLimits;
use App\Support\ImageStorage;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LoanCollateralController extends Controller
{
    use InteractsWithLoans;

    public function requestCollateralReturn(Request $request, string $role, int $loan, RequestCollateralReturnAction $requestReturn, RecordActivity $audit, ImageStorage $images): RedirectResponse
    {
        $this->authorize('collect-installments');
        $this->loanForRole($request, $role, $loan);
        $data = $request->validate([
            'alasan' => ['required', 'string', 'min:5', 'max:2000'],
            'bukti_jaminan' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.ImageLimits::SOURCE_MAX_KILOBYTES],
            'bukti_lunas' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.ImageLimits::SOURCE_MAX_KILOBYTES],
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
            'foto' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.ImageLimits::SOURCE_MAX_KILOBYTES],
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
}
