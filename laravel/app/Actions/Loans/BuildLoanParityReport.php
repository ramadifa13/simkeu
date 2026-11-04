<?php

namespace App\Actions\Loans;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Read-only parity summary for the native loan lifecycle. */
class BuildLoanParityReport
{
    public function handle(): array
    {
        $local = $this->summary(DB::connection());
        $database = (string) config('parallel-run.native_reference_database');
        if (blank($database) || $database === (string) config('database.connections.'.config('database.default').'.database')) {
            return ['laravel' => $local, 'native' => null, 'comparison' => [], 'status' => 'Database referensi native belum valid.'];
        }
        try {
            $native = $this->summary(DB::connection('native_reference'));

            return ['laravel' => $local, 'native' => $native, 'comparison' => $this->compare($local, $native), 'status' => 'Perbandingan baca-saja terhadap database native.'];
        } catch (Throwable $e) {
            report($e);

            return ['laravel' => $local, 'native' => null, 'comparison' => [], 'status' => 'Koneksi atau struktur database native tidak dapat dibaca.'];
        }
    }

    private function summary(ConnectionInterface $db): array
    {
        $loans = $db->table('pinjaman')->selectRaw("COUNT(*) records, COALESCE(SUM(jumlah_pinjaman),0) plafon, COALESCE(SUM(sisa_pokok),0) sisa, COALESCE(SUM(status='aktif'),0) aktif, COALESCE(SUM(status='lunas'),0) lunas, COALESCE(SUM(status='macet'),0) macet")->first();
        $installments = $db->table('angsuran')->selectRaw("COUNT(*) records, COALESCE(SUM(status='lunas'),0) lunas, COALESCE(SUM(status<>'lunas'),0) terbuka, COALESCE(SUM(denda),0) denda")->first();

        return ['pinjaman' => (array) $loans, 'angsuran' => (array) $installments];
    }

    private function compare(array $local, array $native): array
    {
        return collect(['pinjaman', 'angsuran'])->map(fn ($key) => ['key' => $key, 'same' => collect($local[$key])->every(fn ($v, $field) => round((float) $v, 2) === round((float) ($native[$key][$field] ?? 0), 2)), 'laravel' => $local[$key], 'native' => $native[$key]])->all();
    }
}
