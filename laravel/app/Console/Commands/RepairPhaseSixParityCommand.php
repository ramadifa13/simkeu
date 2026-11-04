<?php

namespace App\Console\Commands;

use App\Actions\Cash\RecalculateCashLedgerAction;
use App\Models\ActivityLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RepairPhaseSixParityCommand extends Command
{
    protected $signature = 'phase6:repair-parity {--apply : Jalankan koreksi yang sudah diverifikasi}';

    protected $description = 'Sinkronkan artefak data uji Laravel dengan transaksi Waserda native yang telah diverifikasi.';

    /** Data uji yang ditemukan dari pemeriksaan parity 4 Nov 2026. */
    private const LOCAL_SAVINGS_CODES = [
        'SET-261104081143-187',
        'TAR-261104081306-809',
        'SET-261104081930-628',
    ];

    private const LOCAL_WASERDA_CODE = 'WSD-261104115634-293';

    private const NATIVE_WASERDA_CODE = 'WSD-261104-0379';

    public function handle(RecalculateCashLedgerAction $cashLedger): int
    {
        $native = DB::connection('native_reference')->table('waserda_transaksi')
            ->where('kode_transaksi', self::NATIVE_WASERDA_CODE)
            ->first();
        $localWaserda = DB::table('waserda_transaksi')->where('kode_transaksi', self::LOCAL_WASERDA_CODE)->first();
        $localSavings = DB::table('transaksi')->whereIn('kode_transaksi', self::LOCAL_SAVINGS_CODES)->get();
        $localSavingAccountIds = DB::table('simpanan as s')
            ->join('anggota as a', 'a.id', '=', 's.anggota_id')
            ->join('jenis_simpanan as j', 'j.id', '=', 's.jenis_simpanan_id')
            ->where('a.kode_anggota', 'AGT0009')
            ->where('j.kode_jenis', 'POKOK')
            ->pluck('s.id');

        if (! $native || ! $localWaserda || $localSavings->count() !== count(self::LOCAL_SAVINGS_CODES)
            || $localSavingAccountIds->count() !== 1
            || round((float) $localSavings->sum('jumlah'), 2) !== 3000000.0
            || round((float) $localWaserda->total_bayar, 2) !== 200000.0
            || round((float) $native->total_bayar, 2) !== 150000.0) {
            throw new RuntimeException('Target koreksi parity tidak sesuai hasil verifikasi. Tidak ada perubahan yang dilakukan.');
        }

        $nativeMember = DB::connection('native_reference')->table('anggota')->where('id', $native->anggota_id)->first();
        $member = $nativeMember ? DB::table('anggota')->where('kode_anggota', $nativeMember->kode_anggota)->first() : null;

        if (! $member || DB::table('waserda_transaksi')->where('kode_transaksi', self::NATIVE_WASERDA_CODE)->exists()) {
            throw new RuntimeException('Anggota tujuan tidak ditemukan atau transaksi native sudah pernah diimpor. Tidak ada perubahan dilakukan.');
        }

        if (! $this->option('apply')) {
            $this->warn('Mode pratinjau: tidak ada data yang diubah. Jalankan dengan --apply setelah meninjau target.');
            $this->table(['Jenis', 'Target'], [
                ['Hapus transaksi simpanan uji', implode(', ', self::LOCAL_SAVINGS_CODES)],
                ['Hapus Waserda uji', self::LOCAL_WASERDA_CODE],
                ['Impor Waserda native', self::NATIVE_WASERDA_CODE.' → '.$member->kode_anggota],
            ]);

            return self::SUCCESS;
        }

        DB::transaction(function () use ($cashLedger, $native, $localWaserda, $localSavings, $localSavingAccountIds, $member): void {
            DB::table('kas')->whereIn('transaksi_id', $localSavings->pluck('id'))->delete();
            DB::table('transaksi')->whereIn('id', $localSavings->pluck('id'))->delete();

            DB::table('kas')->where('transaksi_id', $localWaserda->id)
                ->where('keterangan', 'like', '%Waserda%')->delete();
            DB::table('waserda_transaksi')->where('id', $localWaserda->id)->delete();

            $localWaserdaId = DB::table('waserda_transaksi')->insertGetId([
                'kode_transaksi' => $native->kode_transaksi,
                'anggota_id' => $member->id,
                'items' => $native->items,
                'total_bayar' => $native->total_bayar,
                'metode_bayar' => $native->metode_bayar,
                'user_id' => $this->localActorId((int) $native->user_id),
                'created_at' => $native->created_at,
            ]);

            DB::table('kas')->insert([
                'transaksi_id' => $localWaserdaId,
                'jenis' => 'masuk',
                'jumlah' => $native->total_bayar,
                'saldo_sebelum' => 0,
                'saldo_sesudah' => 0,
                'keterangan' => 'Pembelian Waserda - '.$native->kode_transaksi.' ('.count(json_decode($native->items, true, 512, JSON_THROW_ON_ERROR)).' item)',
                'tanggal' => $native->created_at,
            ]);

            DB::table('simpanan')->whereIn('id', $localSavingAccountIds)->delete();

            $cashLedger->recalculateLocked();

            ActivityLog::query()->create([
                'user_id' => $this->localActorId((int) $native->user_id),
                'action' => 'phase6.parity_repaired',
                'auditable_type' => 'phase6_parity',
                'auditable_id' => $localWaserdaId,
                'context' => [
                    'removed_savings_codes' => self::LOCAL_SAVINGS_CODES,
                    'removed_waserda_code' => self::LOCAL_WASERDA_CODE,
                    'imported_waserda_code' => self::NATIVE_WASERDA_CODE,
                    'reference_database' => config('parallel-run.native_reference_database'),
                ],
                'ip_address' => null,
                'user_agent' => 'artisan:phase6:repair-parity',
            ]);
        }, 3);

        $this->info('Koreksi parity berhasil diterapkan dan buku kas dihitung ulang.');

        return self::SUCCESS;
    }

    private function localActorId(int $nativeUserId): int
    {
        return (int) (DB::table('users')->where('id', $nativeUserId)->value('id')
            ?? DB::table('users')->orderBy('id')->value('id'));
    }
}
