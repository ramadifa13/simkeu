<?php

namespace App\Actions\Cash;

use Illuminate\Support\Facades\DB;

class RecalculateCashLedgerAction
{
    /** @return array{entries:int,saldo_akhir:float} */
    public function handle(): array
    {
        return DB::transaction(fn (): array => $this->recalculateLocked(), 3);
    }

    /**
     * Dipakai oleh mutasi lain yang telah membuka transaksi database sendiri.
     *
     * @return array{entries:int,saldo_akhir:float}
     */
    public function recalculateLocked(): array
    {
        $balance = 0.0;
        $entries = 0;
        $rows = DB::table('kas')->orderBy('tanggal')->orderBy('id')->lockForUpdate()->get(['id', 'jenis', 'jumlah']);

        foreach ($rows as $row) {
            $before = $balance;
            $balance += $row->jenis === 'masuk' ? (float) $row->jumlah : -(float) $row->jumlah;

            DB::table('kas')->where('id', $row->id)->update([
                'saldo_sebelum' => $before,
                'saldo_sesudah' => $balance,
            ]);
            $entries++;
        }

        return ['entries' => $entries, 'saldo_akhir' => $balance];
    }
}
