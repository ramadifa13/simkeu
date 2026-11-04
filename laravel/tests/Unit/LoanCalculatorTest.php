<?php

namespace Tests\Unit;

use App\Actions\Loans\LoanCalculator;
use Tests\TestCase;

class LoanCalculatorTest extends TestCase
{
    public function test_flat_rate_native_calculation_preserves_last_installment_rounding(): void
    {
        $result = app(LoanCalculator::class)->calculate(2000000, 35, 3, 'mingguan', 'flat');

        $this->assertSame(12, $result['jumlah_cicilan']);
        $this->assertSame(700000.0, $result['total_bunga']);
        $this->assertSame(2700000.0, $result['total']);
        $this->assertSame(225000.0, $result['angsuran']);
        $this->assertSame(2000000.0, array_sum(array_column($result['rincian'], 'pokok')));
        $this->assertSame(2700000.0, array_sum(array_column($result['rincian'], 'jumlah')));
    }

    public function test_frequency_and_due_date_follow_native_contract(): void
    {
        $calculator = app(LoanCalculator::class);

        $this->assertSame(6, $calculator->calculate(1000000, 10, 3, 'dua_mingguan')['jumlah_cicilan']);
        $this->assertSame('2026-02-28', $calculator->dueDate('2026-01-31', 1, 'bulanan'));
        $this->assertSame('2026-01-15', $calculator->dueDate('2026-01-01', 2, 'mingguan'));
        $this->assertSame('2026-01-29', $calculator->dueDate('2026-01-01', 2, 'dua_mingguan'));
    }
}
