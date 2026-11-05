<?php

namespace App\Console\Commands;

use App\Actions\Loans\RefreshLoanPenaltiesAction;
use App\Jobs\RefreshLoanPenaltiesJob;
use App\Support\LoanLifecycle;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RefreshLoanPenaltiesCommand extends Command
{
    protected $signature = 'loans:refresh-penalties
                            {--date= : Tanggal perhitungan YYYY-MM-DD}
                            {--sync : Jalankan sinkron tanpa antrean}';

    protected $description = 'Refresh status terlambat/denda dan sync aktif↔macet untuk pinjaman kolektibel.';

    public function handle(RefreshLoanPenaltiesAction $refresh): int
    {
        $date = $this->option('date') ?: today()->toDateString();
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $this->error('Tanggal tidak valid.');

            return self::FAILURE;
        }

        $ids = DB::table('pinjaman')
            ->whereIn('status', LoanLifecycle::collectibleStatuses())
            ->orderBy('id')
            ->pluck('id');

        if ($this->option('sync') || config('queue.default') === 'sync') {
            $count = 0;
            foreach ($ids as $id) {
                $refresh->handle((int) $id, $date);
                $count++;
            }
            $this->info("Denda diperbarui sinkron untuk {$count} pinjaman kolektibel per {$date}.");

            return self::SUCCESS;
        }

        foreach ($ids as $id) {
            RefreshLoanPenaltiesJob::dispatch((int) $id, $date);
        }

        $this->info($ids->count().' job refresh denda diantrekan untuk '.$date.'. Jalankan queue:work.');

        return self::SUCCESS;
    }
}
