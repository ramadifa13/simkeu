<?php

namespace App\Console\Commands;

use App\Actions\Loans\BuildLoanParityReport;
use Illuminate\Console\Command;

class LoanParityReportCommand extends Command
{
    protected $signature = 'phase7:loan-parity';

    protected $description = 'Read-only comparison of Laravel and native loan summaries.';

    public function handle(BuildLoanParityReport $report): int
    {
        $data = $report->handle();
        $this->info($data['status']);
        if (! $data['native']) {
            return self::FAILURE;
        }
        foreach ($data['comparison'] as $row) {
            $this->line($row['key'].': '.($row['same'] ? 'SAMA' : 'BERBEDA'));
            if ($row['same']) {
                continue;
            }
            foreach ($row['laravel'] as $field => $value) {
                $native = $row['native'][$field] ?? 0;
                if (round((float) $value, 2) === round((float) $native, 2)) {
                    continue;
                }
                $this->line("  - {$field}: laravel=".round((float) $value, 2).' | native='.round((float) $native, 2));
            }
        }

        return collect($data['comparison'])->every('same') ? self::SUCCESS : self::FAILURE;
    }
}
