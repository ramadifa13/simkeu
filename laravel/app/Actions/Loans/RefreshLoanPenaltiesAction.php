<?php

namespace App\Actions\Loans;

use App\Support\LoanLifecycle;
use Illuminate\Support\Facades\DB;

class RefreshLoanPenaltiesAction
{
    public function __construct(private readonly LoanPenaltyCalculator $calculator) {}

    /** Refreshes non-manual penalties for one loan and syncs aktif/macet from overdue days. */
    public function handle(int $loanId, string $referenceDate): int
    {
        return DB::transaction(function () use ($loanId, $referenceDate): int {
            $loan = DB::table('pinjaman as p')
                ->join('jenis_pinjaman as jp', 'jp.id', '=', 'p.jenis_pinjaman_id')
                ->where('p.id', $loanId)
                ->lockForUpdate()
                ->first(['p.id', 'p.status', 'p.jumlah_pinjaman', 'jp.memerlukan_jaminan']);

            if (! $loan) {
                return 0;
            }

            $settings = DB::table('layanan_pengaturan')->orderBy('id')->lockForUpdate()->first();
            $rates = DB::table('denda_tarif')->orderBy('jumlah_pinjaman')->lockForUpdate()
                ->get(['jumlah_pinjaman', 'denda_per_hari'])->map(fn ($rate): array => (array) $rate)->all();
            $installments = DB::table('angsuran')->where('pinjaman_id', $loanId)
                ->whereIn('status', ['belum_bayar', 'telat'])->orderBy('angsuran_ke')->lockForUpdate()->get();
            $updated = 0;

            foreach ($installments as $installment) {
                if ((bool) ($installment->denda_manual ?? false) || $installment->tanggal_jatuh_tempo >= $referenceDate) {
                    continue;
                }

                $next = $installments->first(fn ($candidate) => $candidate->angsuran_ke > $installment->angsuran_ke);
                $penalty = $this->calculator->calculate(
                    (float) $loan->jumlah_pinjaman,
                    (bool) $loan->memerlukan_jaminan,
                    $installment->tanggal_jatuh_tempo,
                    $referenceDate,
                    $next?->tanggal_jatuh_tempo,
                    $rates,
                    (float) ($settings->denda_jaminan_per_juta ?? 5000),
                );

                DB::table('angsuran')->where('id', $installment->id)->update([
                    'denda' => $penalty['denda'],
                    'keterlambatan_hari' => $penalty['hari_telat'],
                    'status' => 'telat',
                ]);
                $updated++;
            }

            if (LoanLifecycle::isCollectible((string) $loan->status)) {
                $maxLate = (int) DB::table('angsuran')
                    ->where('pinjaman_id', $loanId)
                    ->where('status', '<>', 'lunas')
                    ->max('keterlambatan_hari');
                $nextStatus = $maxLate >= LoanLifecycle::overdueDaysForMacet() ? 'macet' : 'aktif';
                if ($nextStatus !== (string) $loan->status) {
                    DB::table('pinjaman')->where('id', $loanId)->update(['status' => $nextStatus]);
                }
            }

            return $updated;
        }, 3);
    }
}
