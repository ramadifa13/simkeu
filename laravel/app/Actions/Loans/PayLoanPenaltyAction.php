<?php

namespace App\Actions\Loans;

use App\Actions\Cash\RecalculateCashLedgerAction;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PayLoanPenaltyAction
{
    public function __construct(private readonly RecalculateCashLedgerAction $cashLedger) {}

    /** @return array{loan_id:int,installment_id:int,total:float,remaining:float} */
    public function handle(int $installmentId, float $amount, string $method, string $paymentDate, User $actor, string $proof = ''): array
    {
        return DB::transaction(function () use ($installmentId, $amount, $method, $paymentDate, $actor, $proof): array {
            if (! Schema::hasTable('layanan_bayar_denda')) {
                throw new DomainException('Tabel pembayaran denda belum tersedia.');
            }
            if ($amount <= 0 || ! in_array($method, ['cash', 'transfer'], true)) {
                throw new DomainException('Nominal atau metode pembayaran denda tidak valid.');
            }
            $installment = DB::table('angsuran')->where('id', $installmentId)->lockForUpdate()->first();
            if (! $installment || $installment->status === 'lunas') {
                throw new DomainException('Angsuran tidak dapat menerima pembayaran denda.');
            }
            $loan = DB::table('pinjaman')->where('id', $installment->pinjaman_id)->lockForUpdate()->first();
            if (! $loan || $loan->status !== 'aktif') {
                throw new DomainException('Pinjaman tidak aktif.');
            }
            $paid = (float) DB::table('layanan_bayar_denda')->where('angsuran_id', $installmentId)->lockForUpdate()->sum('jumlah');
            $remaining = max(0, (float) $installment->denda - $paid);
            if ($amount - $remaining > 0.005) {
                throw new DomainException('Nominal melebihi sisa denda.');
            }

            DB::table('kas')->orderByDesc('id')->limit(1)->lockForUpdate()->get();
            $before = (float) DB::table('kas')->selectRaw("COALESCE(SUM(CASE WHEN jenis='masuk' THEN jumlah ELSE -jumlah END),0) as saldo")->value('saldo');
            $cashId = DB::table('kas')->insertGetId(['transaksi_id' => $installmentId, 'jenis' => 'masuk', 'jumlah' => $amount, 'saldo_sebelum' => $before, 'saldo_sesudah' => $before + $amount, 'keterangan' => 'Pembayaran denda angsuran ke-'.$installment->angsuran_ke.' '.$loan->kode_pinjaman, 'tanggal' => $paymentDate.' '.now()->format('H:i:s')]);
            $payment = [
                'angsuran_id' => $installmentId,
                'pinjaman_id' => $loan->id,
                'user_id' => $actor->id,
                'jumlah' => round($amount, 2),
                'metode' => $method,
                'bukti' => $proof,
                'approved_by' => $actor->role === 'admin' ? $actor->id : null,
                'created_at' => $paymentDate.' '.now()->format('H:i:s'),
                'hari_telat' => $installment->keterlambatan_hari,
                'tarif' => $installment->keterlambatan_hari > 0 ? round((float) $installment->denda / $installment->keterlambatan_hari, 2) : 0,
                'denda_awal' => $installment->denda,
                'denda_akhir' => $installment->denda,
            ];
            if (Schema::hasColumn('layanan_bayar_denda', 'transaksi_id')) {
                $payment['transaksi_id'] = $cashId;
            }
            DB::table('layanan_bayar_denda')->insert($payment);
            $this->cashLedger->recalculateLocked();

            return ['loan_id' => (int) $loan->id, 'installment_id' => $installmentId, 'total' => round($amount, 2), 'remaining' => round($remaining - $amount, 2)];
        }, 3);
    }
}
