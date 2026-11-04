<?php

namespace App\Console\Commands;

use App\Actions\Loans\RefreshLoanPenaltiesAction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RefreshLoanPenaltiesCommand extends Command
{
    protected $signature = 'loans:refresh-penalties {--date= : Tanggal perhitungan YYYY-MM-DD}';

    protected $description = 'Refresh status terlambat/denda dan sync aktif↔macet untuk pinjaman kolektibel.';

    public function handle(RefreshLoanPenaltiesAction $refresh): int
    {
        $date = $this->option('date') ?: today()->toDateString();
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $this->error('Tanggal tidak valid.');

            return self::FAILURE;
        }
        $count = 0;
        DB::table('pinjaman')->whereIn('status', \App\Support\LoanLifecycle::collectibleStatuses())->orderBy('id')->pluck('id')->each(function (int $id) use ($refresh, $date, &$count): void {
            $refresh->handle($id, $date);
            $count++;
        });
        $this->info("Denda diperbarui untuk {$count} pinjaman kolektibel per {$date}.");

        return self::SUCCESS;
    }
}
