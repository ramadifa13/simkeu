<?php

namespace App\Actions\Waserda;

use App\Actions\Cash\RecalculateCashLedgerAction;
use App\Models\WaserdaTransaction;
use DomainException;
use Illuminate\Support\Facades\DB;

class DeleteWaserdaTransactionAction
{
    public function __construct(private readonly RecalculateCashLedgerAction $cashLedger) {}

    /**
     * Hapus transaksi sesuai perilaku native dan hitung ulang saldo kas yang tersisa.
     * Auditnya sengaja dicatat oleh controller setelah snapshot dikembalikan.
     *
     * @return array{kode_transaksi:string,anggota_id:int,total_bayar:float,kas_dihapus:int}
     */
    public function handle(WaserdaTransaction $waserda): array
    {
        return DB::transaction(function () use ($waserda): array {
            $transaction = WaserdaTransaction::query()->whereKey($waserda->getKey())->lockForUpdate()->first();

            if (! $transaction) {
                throw new DomainException('Transaksi Waserda tidak lagi tersedia.');
            }

            // Serialize deletion with all cash postings before recalculating balances.
            DB::table('kas')->orderByDesc('id')->limit(1)->lockForUpdate()->get();

            $snapshot = [
                'kode_transaksi' => $transaction->kode_transaksi,
                'anggota_id' => (int) $transaction->anggota_id,
                'total_bayar' => (float) $transaction->total_bayar,
            ];

            $deletedCashEntries = DB::table('kas')
                ->where('transaksi_id', $transaction->id)
                ->where('keterangan', 'like', '%Waserda%')
                ->delete();

            $transaction->delete();

            $this->cashLedger->recalculateLocked();

            return $snapshot + ['kas_dihapus' => $deletedCashEntries];
        }, 3);
    }
}
