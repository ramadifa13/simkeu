<?php

namespace App\Console\Commands;

use App\Actions\Finance\BuildPhaseSixParityReport;
use App\Actions\Loans\BuildLoanParityReport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Combined read-only cutover gate: financial + loan parity + integrity checks. */
class CutoverUatGateCommand extends Command
{
    protected $signature = 'simkeu:uat-gate {--json : Cetak hasil sebagai JSON}';

    protected $description = 'Gate UAT cutover baca-saja: parity fase 6+7 dan integritas pinjaman/closing.';

    public function handle(BuildPhaseSixParityReport $phaseSix, BuildLoanParityReport $phaseSeven): int
    {
        $errors = [];
        $warnings = [];

        $finance = $phaseSix->handle();
        if ($finance['native'] === null) {
            $warnings[] = 'Fase 6: '.$finance['native_status'];
        } else {
            foreach ($finance['comparison'] as $row) {
                if (! ($row['same'] ?? false)) {
                    $errors[] = 'Fase 6 berbeda pada '.$row['key'].'.';
                }
            }
            if (($finance['account_differences'] ?? []) !== []) {
                $errors[] = 'Fase 6: ada perbedaan saldo akun ('.count($finance['account_differences']).' baris).';
            }
        }

        $loans = $phaseSeven->handle();
        if ($loans['native'] === null) {
            $warnings[] = 'Fase 7: '.$loans['status'];
        } else {
            foreach ($loans['comparison'] as $row) {
                if (! ($row['same'] ?? false)) {
                    $errors[] = 'Fase 7 berbeda pada '.$row['key'].'.';
                }
            }
        }

        $invalidLoan = DB::table('pinjaman as p')
            ->where('p.status', 'lunas')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('angsuran as a')->whereColumn('a.pinjaman_id', 'p.id')->where('a.status', '<>', 'lunas'))
            ->count();
        if ($invalidLoan) {
            $errors[] = "{$invalidLoan} pinjaman lunas masih memiliki angsuran terbuka.";
        }

        if (DB::getSchemaBuilder()->hasTable('closing_angsuran_detail') && DB::getSchemaBuilder()->hasTable('closing_angsuran_bulanan')) {
            $invalidClosing = DB::table('closing_angsuran_detail as d')
                ->join('closing_angsuran_bulanan as c', 'c.id', '=', 'd.closing_id')
                ->where('c.status', 'terkunci')
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('angsuran as a')->whereColumn('a.id', 'd.angsuran_id')->where('a.status', 'lunas'))
                ->count();
            if ($invalidClosing) {
                $errors[] = "{$invalidClosing} detail closing terkunci tidak lagi berstatus lunas.";
            }
        }

        $payload = [
            'ok' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
            'finance_status' => $finance['native_status'] ?? null,
            'loan_status' => $loans['status'] ?? null,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return $errors === [] ? self::SUCCESS : self::FAILURE;
        }

        foreach ($warnings as $warning) {
            $this->warn($warning);
        }
        foreach ($errors as $error) {
            $this->error($error);
        }

        if ($errors !== []) {
            $this->error('UAT cutover GATE GAGAL.');

            return self::FAILURE;
        }

        $this->info('UAT cutover GATE LULUS (parity + integritas inti).');
        if ($warnings !== []) {
            $this->comment('Ada peringatan (native belum terkoneksi). Bandingkan angka manual sebelum cutover.');
        }

        return self::SUCCESS;
    }
}
