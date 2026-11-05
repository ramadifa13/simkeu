<?php

namespace App\Jobs;

use App\Actions\Loans\RefreshLoanPenaltiesAction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class RefreshLoanPenaltiesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly int $loanId,
        public readonly string $referenceDate,
    ) {}

    public function handle(RefreshLoanPenaltiesAction $refresh): void
    {
        $updated = $refresh->handle($this->loanId, $this->referenceDate);
        Log::info('money.loan.penalties.refreshed', [
            'pinjaman_id' => $this->loanId,
            'tanggal' => $this->referenceDate,
            'angsuran_updated' => $updated,
        ]);
    }
}
