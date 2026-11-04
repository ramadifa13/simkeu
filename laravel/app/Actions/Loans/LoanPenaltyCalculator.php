<?php

namespace App\Actions\Loans;

use Carbon\CarbonImmutable;

/**
 * Perhitungan murni denda angsuran yang setara dengan denda_helper.php legacy.
 *
 * Denda cicilan hanya berjalan sejak hari setelah jatuh tempo dan, jika masih
 * ada cicilan berikutnya, berhenti pada jatuh tempo cicilan tersebut.
 */
class LoanPenaltyCalculator
{
    /**
     * @param  list<array{jumlah_pinjaman:float|int|string,denda_per_hari:float|int|string}>  $rates
     * @return array{hari_telat:int,hari_dihitung:int,per_hari:float,denda:float}
     */
    public function calculate(
        float $principal,
        bool $requiresCollateral,
        string $dueDate,
        string $referenceDate,
        ?string $nextDueDate,
        array $rates,
        float $collateralRatePerMillion = 5000.0,
    ): array {
        $due = CarbonImmutable::parse($dueDate)->startOfDay();
        $reference = CarbonImmutable::parse($referenceDate)->startOfDay();
        $daysLate = max(0, (int) $due->diffInDays($reference, false));
        $daysToCount = $daysLate;

        if ($nextDueDate !== null) {
            $nextDue = CarbonImmutable::parse($nextDueDate)->startOfDay();
            $daysToCount = min($daysToCount, max(0, (int) $due->diffInDays($nextDue, false)));
        }

        $dailyRate = $requiresCollateral
            ? max(1, (int) floor($principal / 1_000_000)) * max(0, $collateralRatePerMillion)
            : $this->rateForPrincipal($principal, $rates);

        return [
            'hari_telat' => $daysLate,
            'hari_dihitung' => $daysToCount,
            'per_hari' => round($dailyRate, 2),
            'denda' => round($dailyRate * $daysToCount, 2),
        ];
    }

    /** @param list<array{jumlah_pinjaman:float|int|string,denda_per_hari:float|int|string}> $rates */
    private function rateForPrincipal(float $principal, array $rates): float
    {
        usort($rates, fn (array $left, array $right): int => (float) $left['jumlah_pinjaman'] <=> (float) $right['jumlah_pinjaman']);
        if ($rates === []) {
            return 0.0;
        }

        $selected = $rates[0];
        foreach ($rates as $rate) {
            if ((float) $rate['jumlah_pinjaman'] <= $principal + 0.001) {
                $selected = $rate;
            }
        }

        return max(0, (float) $selected['denda_per_hari']);
    }
}
