<?php

namespace App\Console\Commands;

use App\Actions\Loans\RefreshLoanPenaltiesAction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RefreshLoanPenaltiesCommand extends Command
{
    protected $signature = 'loans:refresh-penalties {--date= : Tanggal perhitungan YYYY-MM-DD}';

    protected $description = 'Refresh status terlambat dan denda seluruh pinjaman aktif.';

    public function handle(RefreshLoanPenaltiesAction $refresh): int
    {
        $date = $this->option('date') ?: today()->toDateString();
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $this->error('Tanggal tidak valid.');

            return self::FAILURE;
        }
        $count = 0;
        DB::table('pinjaman')->where('status', 'aktif')->orderBy('id')->pluck('id')->each(function (int $id) use ($refresh, $date, &$count): void {
            $refresh->handle($id, $date);
            $count++;
        });
        $this->info("Denda diperbarui untuk {$count} pinjaman aktif per {$date}.");

        return self::SUCCESS;
    }
}
