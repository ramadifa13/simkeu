<?php

namespace App\Actions\Finance;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

class BuildPhaseSixParityReport
{
    /** @return array{laravel:array<string, array<string, int|float|string|null>>,native:array<string, array<string, int|float|string|null>>|null,comparison:list<array<string, mixed>>,account_differences:list<array<string, mixed>>,native_status:string} */
    public function handle(): array
    {
        $laravel = $this->summary(DB::connection());
        $nativeDatabase = (string) config('parallel-run.native_reference_database');

        if (blank($nativeDatabase)) {
            return [
                'laravel' => $laravel,
                'native' => null,
                'comparison' => [],
                'account_differences' => [],
                'native_status' => 'Database referensi native belum dikonfigurasi. Isi NATIVE_REFERENCE_DATABASE untuk menjalankan perbandingan.',
            ];
        }

        if ($nativeDatabase === (string) config('database.connections.'.config('database.default').'.database')) {
            return [
                'laravel' => $laravel,
                'native' => null,
                'comparison' => [],
                'account_differences' => [],
                'native_status' => 'Database referensi tidak boleh sama dengan database Laravel.',
            ];
        }

        try {
            $native = $this->summary(DB::connection('native_reference'));

            return [
                'laravel' => $laravel,
                'native' => $native,
                'comparison' => $this->compare($laravel, $native),
                'account_differences' => $this->accountDifferences(DB::connection(), DB::connection('native_reference')),
                'native_status' => 'Perbandingan memakai koneksi baca-saja ke database referensi native.',
            ];
        } catch (Throwable $exception) {
            report($exception);

            return [
                'laravel' => $laravel,
                'native' => null,
                'comparison' => [],
                'account_differences' => [],
                'native_status' => 'Database referensi tidak dapat dibaca. Periksa koneksi dan struktur tabel native tanpa mengubah datanya.',
            ];
        }
    }

    /** @return array<string, array<string, int|float|string|null>> */
    private function summary(ConnectionInterface $connection): array
    {
        return [
            'simpanan' => $this->aggregate($connection, 'simpanan', 'saldo'),
            'kas' => $this->cashAggregate($connection),
            'waserda' => $this->aggregate($connection, 'waserda_transaksi', 'total_bayar'),
        ];
    }

    /** @return array{count:int,total:float,last_balance:float|null} */
    private function aggregate(ConnectionInterface $connection, string $table, string $amountColumn): array
    {
        $row = $connection->table($table)
            ->selectRaw("COUNT(*) AS records, COALESCE(SUM({$amountColumn}), 0) AS total")
            ->first();

        return ['count' => (int) $row->records, 'total' => (float) $row->total, 'last_balance' => null];
    }

    /** @return array{count:int,total:float,last_balance:float|null} */
    private function cashAggregate(ConnectionInterface $connection): array
    {
        $row = $connection->table('kas')
            ->selectRaw("COUNT(*) AS records, COALESCE(SUM(CASE WHEN jenis = 'masuk' THEN jumlah ELSE -jumlah END), 0) AS total")
            ->first();
        $lastBalance = $connection->table('kas')->orderByDesc('tanggal')->orderByDesc('id')->value('saldo_sesudah');

        return ['count' => (int) $row->records, 'total' => (float) $row->total, 'last_balance' => $lastBalance === null ? null : (float) $lastBalance];
    }

    /** @param array<string, array<string, int|float|string|null>> $laravel @param array<string, array<string, int|float|string|null>> $native @return list<array<string, mixed>> */
    private function compare(array $laravel, array $native): array
    {
        return collect(['simpanan' => 'Saldo rekening simpanan', 'kas' => 'Mutasi dan saldo kas', 'waserda' => 'Total Waserda'])
            ->map(function (string $label, string $key) use ($laravel, $native): array {
                $sameCount = $laravel[$key]['count'] === $native[$key]['count'];
                $sameTotal = round((float) $laravel[$key]['total'], 2) === round((float) $native[$key]['total'], 2);
                $sameLastBalance = $key !== 'kas' || round((float) $laravel[$key]['last_balance'], 2) === round((float) $native[$key]['last_balance'], 2);

                return [
                    'label' => $label,
                    'key' => $key,
                    'same' => $sameCount && $sameTotal && $sameLastBalance,
                    'laravel' => $laravel[$key],
                    'native' => $native[$key],
                ];
            })
            ->values()
            ->all();
    }

    /** @return list<array{rekening:string,laravel:float,native:float,selisih:float}> */
    private function accountDifferences(ConnectionInterface $laravel, ConnectionInterface $native): array
    {
        $localBalances = $this->accountBalances($laravel);
        $nativeBalances = $this->accountBalances($native);

        return collect(array_unique([...array_keys($localBalances), ...array_keys($nativeBalances)]))
            ->map(function (string $key) use ($localBalances, $nativeBalances): array {
                $local = $localBalances[$key] ?? 0.0;
                $legacy = $nativeBalances[$key] ?? 0.0;

                return [
                    'rekening' => $key,
                    'laravel' => $local,
                    'native' => $legacy,
                    'selisih' => round($local - $legacy, 2),
                ];
            })
            ->filter(fn (array $row): bool => $row['selisih'] !== 0.0)
            ->take(100)
            ->values()
            ->all();
    }

    /** @return array<string, float> */
    private function accountBalances(ConnectionInterface $connection): array
    {
        return $connection->table('simpanan as s')
            ->join('anggota as a', 'a.id', '=', 's.anggota_id')
            ->join('jenis_simpanan as j', 'j.id', '=', 's.jenis_simpanan_id')
            ->select(['a.kode_anggota', 'j.kode_jenis', 's.saldo'])
            ->get()
            ->mapWithKeys(fn (object $row): array => [$row->kode_anggota.' · '.$row->kode_jenis => (float) $row->saldo])
            ->all();
    }
}
