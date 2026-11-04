<?php

namespace App\Actions\Loans;

use Carbon\CarbonImmutable;
use DomainException;

class LoanCalculator
{
    /** @return array{pokok:float,bunga_persen:float,skema:string,frekuensi:string,tenor:int,jumlah_cicilan:int,total_bunga:float,total:float,angsuran:float,rincian:list<array{ke:int,pokok:float,bunga:float,jumlah:float}>} */
    public function calculate(float $principal, float $interestPercent, int $tenorMonths, string $frequency = 'bulanan', string $scheme = 'flat'): array
    {
        if ($principal <= 0 || $principal > 999999999999.99) {
            throw new DomainException('Nominal pinjaman tidak valid.');
        }
        if ($interestPercent < 0 || $interestPercent > 1000 || $tenorMonths < 1) {
            throw new DomainException('Tenor atau bunga pinjaman tidak valid.');
        }

        $frequency = in_array($frequency, ['bulanan', 'mingguan', 'dua_mingguan'], true) ? $frequency : 'bulanan';
        $scheme = $scheme === 'tahunan' ? 'tahunan' : 'flat';
        $installmentCount = match ($frequency) {
            'mingguan' => $tenorMonths * 4,
            'dua_mingguan' => $tenorMonths * 2,
            default => $tenorMonths,
        };

        $totalInterest = $scheme === 'tahunan'
            ? round($principal * ($interestPercent / 100) * ($tenorMonths / 12), 2)
            : (float) round($principal * $interestPercent / 100);
        $total = $principal + $totalInterest;
        $regularInstallment = $scheme === 'tahunan' ? $total / $installmentCount : (float) round($total / $installmentCount);
        $regularPrincipal = $scheme === 'tahunan' ? $principal / $installmentCount : (float) round($principal / $installmentCount);
        $paidTotal = 0.0;
        $paidPrincipal = 0.0;
        $details = [];

        for ($number = 1; $number <= $installmentCount; $number++) {
            $amount = $number === $installmentCount ? $total - $paidTotal : $regularInstallment;
            $principalPart = $number === $installmentCount ? $principal - $paidPrincipal : $regularPrincipal;
            $amount = round($amount, 2);
            $principalPart = round($principalPart, 2);
            $details[] = ['ke' => $number, 'pokok' => $principalPart, 'bunga' => round($amount - $principalPart, 2), 'jumlah' => $amount];
            $paidTotal += $amount;
            $paidPrincipal += $principalPart;
        }

        return [
            'pokok' => $principal,
            'bunga_persen' => $interestPercent,
            'skema' => $scheme,
            'frekuensi' => $frequency,
            'tenor' => $tenorMonths,
            'jumlah_cicilan' => $installmentCount,
            'total_bunga' => $totalInterest,
            'total' => $total,
            'angsuran' => $details[0]['jumlah'],
            'rincian' => $details,
        ];
    }

    /** Native gadai weekly fee: 1jt→10rb, 2jt→15rb, …; null if not a multiple of Rp1.000.000. */
    public function weeklyPawnFee(float $principal): ?int
    {
        if ($principal < 1_000_000 || fmod($principal, 1_000_000.0) != 0.0) {
            return null;
        }

        return 5000 + ((int) ($principal / 1_000_000) * 5000);
    }

    /**
     * Gadai: flat base interest + weekly fee × (tenor × 4), stored as effective percent.
     *
     * @return array{pokok:float,bunga_persen:float,skema:string,frekuensi:string,tenor:int,jumlah_cicilan:int,total_bunga:float,total:float,angsuran:float,rincian:list<array{ke:int,pokok:float,bunga:float,jumlah:float}>,bunga_dasar_persen:float,biaya_mingguan_gadai:int,tambahan_gadai:int}
     */
    public function calculatePawn(float $principal, float $baseInterestPercent, int $tenorMonths, string $frequency): array
    {
        $weeklyFee = $this->weeklyPawnFee($principal);
        if ($weeklyFee === null) {
            throw new DomainException('Nominal pinjaman gadai harus kelipatan Rp 1 juta.');
        }

        $extra = $weeklyFee * ($tenorMonths * 4);
        $baseInterestAmount = (float) round($principal * $baseInterestPercent / 100);
        $effectivePercent = (($baseInterestAmount + $extra) / $principal) * 100;
        $result = $this->calculate($principal, $effectivePercent, $tenorMonths, $frequency, 'flat');
        $result['bunga_dasar_persen'] = $baseInterestPercent;
        $result['biaya_mingguan_gadai'] = $weeklyFee;
        $result['tambahan_gadai'] = $extra;

        return $result;
    }

    public function dueDate(string $startDate, int $installmentNumber, string $frequency): string
    {
        $start = CarbonImmutable::parse($startDate)->startOfDay();

        return match ($frequency) {
            'mingguan' => $start->addDays(7 * $installmentNumber)->toDateString(),
            'dua_mingguan' => $start->addDays(14 * $installmentNumber)->toDateString(),
            default => $start->addMonthsNoOverflow($installmentNumber)->toDateString(),
        };
    }
}
