<?php

namespace App\Actions\Savings;

use App\Actions\Cash\RecalculateCashLedgerAction;
use App\Models\Transaksi;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class CorrectSavingsTransactionAction
{
    public function __construct(private readonly RecalculateCashLedgerAction $cashLedger) {}

    /** @return array{kode_transaksi:string,jumlah_lama:float,jumlah_baru:float,selisih:float,keterangan:string} */
    public function handle(Transaksi $transaction, float $newAmount, string $reason, User $actor): array
    {
        return DB::transaction(function () use ($transaction, $newAmount, $reason, $actor): array {
            $entry = Transaksi::query()->whereKey($transaction->getKey())->lockForUpdate()->first();

            if (! $entry || ! in_array($entry->jenis_transaksi, ['setoran', 'penarikan'], true)) {
                throw new DomainException('Hanya transaksi setoran atau penarikan yang dapat dikoreksi.');
            }

            $type = DB::table('jenis_simpanan')->where('id', $entry->jenis_simpanan_id)->lockForUpdate()->first(['kode_jenis']);
            if (! $type || strtoupper((string) $type->kode_jenis) === 'SHU') {
                throw new DomainException('Jurnal SHU terkunci dan tidak dapat dikoreksi dari menu ini.');
            }

            $amount = round($newAmount, 2);
            if ($amount < 0 || $amount > 999999999999) {
                throw new DomainException('Nominal koreksi tidak valid.');
            }

            $oldAmount = (float) $entry->jumlah;
            $difference = round($amount - $oldAmount, 2);

            DB::table('simpanan')
                ->where('anggota_id', $entry->anggota_id)
                ->where('jenis_simpanan_id', $entry->jenis_simpanan_id)
                ->lockForUpdate()
                ->first();
            DB::table('kas')->orderByDesc('id')->limit(1)->lockForUpdate()->get();

            $entry->update([
                'jumlah' => $amount,
                'keterangan' => trim((string) $entry->keterangan.' | Koreksi: '.$reason),
            ]);

            $this->recalculateSavingHistory((int) $entry->anggota_id, (int) $entry->jenis_simpanan_id);
            $this->cashLedger->recalculateLocked();

            $balance = (float) DB::table('simpanan')
                ->where('anggota_id', $entry->anggota_id)
                ->where('jenis_simpanan_id', $entry->jenis_simpanan_id)
                ->value('saldo');

            if ($balance < 0) {
                throw new DomainException('Koreksi ditolak karena menyebabkan saldo simpanan anggota menjadi negatif.');
            }

            DB::table('log_koreksi')->insert([
                'transaksi_id' => $entry->id,
                'jumlah_lama' => $oldAmount,
                'jumlah_baru' => $amount,
                'selisih' => $difference,
                'keterangan' => $reason,
                'user_id' => $actor->id,
            ]);

            return [
                'kode_transaksi' => $entry->kode_transaksi,
                'jumlah_lama' => $oldAmount,
                'jumlah_baru' => $amount,
                'selisih' => $difference,
                'keterangan' => $reason,
            ];
        }, 3);
    }

    private function recalculateSavingHistory(int $memberId, int $savingTypeId): void
    {
        $balance = 0.0;
        $history = DB::table('transaksi')
            ->where('anggota_id', $memberId)
            ->where('jenis_simpanan_id', $savingTypeId)
            ->whereIn('jenis_transaksi', ['setoran', 'penarikan'])
            ->orderBy('tanggal_transaksi')
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'jenis_transaksi', 'jumlah']);

        foreach ($history as $row) {
            $before = $balance;
            $balance += $row->jenis_transaksi === 'setoran' ? (float) $row->jumlah : -(float) $row->jumlah;

            DB::table('transaksi')->where('id', $row->id)->update([
                'saldo_sebelum' => $before,
                'saldo_sesudah' => $balance,
            ]);
        }

        DB::table('simpanan')
            ->where('anggota_id', $memberId)
            ->where('jenis_simpanan_id', $savingTypeId)
            ->update(['saldo' => $balance]);
    }
}
