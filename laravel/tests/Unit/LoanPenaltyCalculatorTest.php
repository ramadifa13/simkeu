<?php

namespace Tests\Unit;

use App\Actions\Loans\LoanPenaltyCalculator;
use Tests\TestCase;

class LoanPenaltyCalculatorTest extends TestCase
{
    public function test_non_collateral_uses_nearest_lower_rate_and_stops_at_next_due_date(): void
    {
        $result = app(LoanPenaltyCalculator::class)->calculate(
            2_900_000,
            false,
            '2026-11-01',
            '2026-11-20',
            '2026-11-15',
            [
                ['jumlah_pinjaman' => 1_000_000, 'denda_per_hari' => 1500],
                ['jumlah_pinjaman' => 2_000_000, 'denda_per_hari' => 2500],
                ['jumlah_pinjaman' => 3_000_000, 'denda_per_hari' => 3500],
            ],
        );

        $this->assertSame(19, $result['hari_telat']);
        $this->assertSame(14, $result['hari_dihitung']);
        $this->assertSame(2500.0, $result['per_hari']);
        $this->assertSame(35000.0, $result['denda']);
    }

    public function test_collateral_penalty_uses_full_million_tiers_with_a_minimum_of_one(): void
    {
        $result = app(LoanPenaltyCalculator::class)->calculate(2_900_000, true, '2026-11-01', '2026-11-04', null, [], 5000);

        $this->assertSame(3, $result['hari_telat']);
        $this->assertSame(10000.0, $result['per_hari']);
        $this->assertSame(30000.0, $result['denda']);
    }
}
