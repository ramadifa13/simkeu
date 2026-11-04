<?php

namespace App\Console\Commands;

use App\Actions\Loans\BuildLoanParityReport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PhaseSevenUatCheckCommand extends Command
{
    protected $signature = 'phase7:uat-check';

    protected $description = 'Read-only integrity and parity gate for the loan lifecycle.';

    public function handle(BuildLoanParityReport $parity): int
    {
        $errors = [];
        $report = $parity->handle();
        if (! $report['native']) {
            $errors[] = 'Database native reference tidak tersedia.';
        } elseif (collect($report['comparison'])->contains(fn (array $row): bool => ! $row['same'])) {
            $errors[] = 'Ringkasan pinjaman/angsuran berbeda dari native.';
        }

        $invalidLoan = DB::table('pinjaman as p')->where('p.status', 'lunas')->whereExists(fn ($q) => $q->selectRaw('1')->from('angsuran as a')->whereColumn('a.pinjaman_id', 'p.id')->where('a.status', '<>', 'lunas'))->count();
        if ($invalidLoan) {
            $errors[] = "{$invalidLoan} pinjaman lunas masih memiliki angsuran terbuka.";
        }
        $invalidClosing = DB::table('closing_angsuran_detail as d')->join('closing_angsuran_bulanan as c', 'c.id', '=', 'd.closing_id')->where('c.status', 'terkunci')->whereNotExists(fn ($q) => $q->selectRaw('1')->from('angsuran as a')->whereColumn('a.id', 'd.angsuran_id')->where('a.status', 'lunas'))->count();
        if ($invalidClosing) {
            $errors[] = "{$invalidClosing} detail closing terkunci tidak lagi berstatus lunas.";
        }

        if ($errors) {
            foreach ($errors as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }
        $this->info('UAT data gate Fase 7 lulus: parity dan integritas inti konsisten.');

        return self::SUCCESS;
    }
}
