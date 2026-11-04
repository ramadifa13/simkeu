<?php

namespace App\Actions\Loans;

use App\Actions\Cash\RecalculateCashLedgerAction;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PayInstallmentAction
{
    public function __construct(private readonly RecalculateCashLedgerAction $cashLedger) {}

    /** @return array{loan_id:int,installment_id:int,cash_id:int,total:float,lunas:bool} */
    public function handle(int $installmentId, string $paymentDate, string $method, User $actor, ?float $manualPenalty = null, string $proof = ''): array
    {
        return DB::transaction(function () use ($installmentId, $paymentDate, $method, $actor, $manualPenalty, $proof): array {
            if (! in_array($method, ['cash', 'transfer'], true)) {
                throw new DomainException('Metode pembayaran tidak valid.');
            }
            if (CarbonImmutable::parse($paymentDate)->isAfter(today())) {
                throw new DomainException('Tanggal pembayaran tidak boleh di masa depan.');
            }
            $installment = DB::table('angsuran')->where('id', $installmentId)->lockForUpdate()->first();
            if (! $installment || $installment->status === 'lunas') {
                throw new DomainException('Angsuran sudah dibayar atau tidak ditemukan.');
            }
            $loan = DB::table('pinjaman')->where('id', $installment->pinjaman_id)->lockForUpdate()->first();
            if (! $loan || ! \App\Support\LoanLifecycle::isCollectible($loan->status)) {
                throw new DomainException('Pinjaman tidak aktif atau sudah lunas.');
            }
            DB::table('kas')->orderByDesc('id')->limit(1)->lockForUpdate()->get();
            $daysLate = max(0, (int) floor((strtotime($paymentDate) - strtotime($installment->tanggal_jatuh_tempo)) / 86400));
            if ($manualPenalty !== null && $actor->role !== 'admin' && abs($manualPenalty - (float) $installment->denda) > 0.005) {
                throw new DomainException('Teller tidak dapat mengubah denda.');
            }
            $penalty = $manualPenalty === null ? (float) $installment->denda : round($manualPenalty, 2);
            if ($penalty < 0) {
                throw new DomainException('Denda tidak valid.');
            }
            $penaltyPaid = Schema::hasTable('layanan_bayar_denda')
                ? (float) DB::table('layanan_bayar_denda')->where('angsuran_id', $installmentId)->lockForUpdate()->sum('jumlah')
                : 0.0;
            if (Schema::hasTable('layanan_bayar_denda') && $penalty - $penaltyPaid > 0.005) {
                throw new DomainException('Bayar sisa denda melalui aksi pembayaran denda terlebih dahulu.');
            }
            $total = (float) $installment->jumlah_angsuran + max(0, $penalty - $penaltyPaid);
            DB::table('angsuran')->where('id', $installmentId)->update(['denda' => $penalty, 'tanggal_bayar' => $paymentDate, 'status' => 'lunas', 'cara_bayar' => 'cicilan', 'keterlambatan_hari' => $daysLate]);
            $remaining = max(0, (float) $loan->sisa_pokok - (float) $installment->pokok);
            $unpaid = DB::table('angsuran')->where('pinjaman_id', $loan->id)->where('id', '<>', $installmentId)->where('status', '<>', 'lunas')->exists();
            DB::table('pinjaman')->where('id', $loan->id)->update(['sisa_pokok' => $remaining, 'status' => $unpaid ? 'aktif' : 'lunas', 'tanggal_lunas' => $unpaid ? null : $paymentDate]);
            $before = (float) DB::table('kas')->selectRaw("COALESCE(SUM(CASE WHEN jenis='masuk' THEN jumlah ELSE -jumlah END),0) as saldo")->value('saldo');
            $cashId = DB::table('kas')->insertGetId(['transaksi_id' => $installmentId, 'jenis' => 'masuk', 'jumlah' => $total, 'saldo_sebelum' => $before, 'saldo_sesudah' => $before + $total, 'keterangan' => 'Pembayaran angsuran ke-'.$installment->angsuran_ke.' '.$loan->kode_pinjaman, 'tanggal' => $paymentDate.' '.now()->format('H:i:s')]);
            if (Schema::hasTable('layanan_pembayaran')) {
                DB::table('layanan_pembayaran')->insert(['angsuran_id' => $installmentId, 'pinjaman_id' => $loan->id, 'user_id' => $actor->id, 'jenis' => 'angsuran', 'jumlah' => $total, 'metode' => $method, 'bukti' => $proof]);
            }
            $this->cashLedger->recalculateLocked();

            return ['loan_id' => (int) $loan->id, 'installment_id' => $installmentId, 'cash_id' => $cashId, 'total' => $total, 'lunas' => ! $unpaid];
        }, 3);
    }
}
