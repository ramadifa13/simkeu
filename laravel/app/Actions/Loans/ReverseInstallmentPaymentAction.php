<?php

namespace App\Actions\Loans;

use App\Actions\Cash\RecalculateCashLedgerAction;
use App\Models\User;
use App\Support\CashEntry;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Reverses an installment via a compensating cash entry; original ledger rows are never deleted. */
class ReverseInstallmentPaymentAction
{
    public function __construct(private readonly RecalculateCashLedgerAction $cashLedger) {}

    public function handle(int $installmentId, User $admin, string $reason): array
    {
        return DB::transaction(function () use ($installmentId, $admin, $reason): array {
            if (mb_strlen(trim($reason)) < 5) {
                throw new DomainException('Alasan pembatalan minimal 5 karakter.');
            }
            $installment = DB::table('angsuran')->where('id', $installmentId)->lockForUpdate()->first();
            if (! $installment || $installment->status !== 'lunas' || $installment->cara_bayar === 'pelunasan') {
                throw new DomainException('Hanya pembayaran cicilan biasa yang dapat dibatalkan dari layar ini.');
            }
            $loan = DB::table('pinjaman')->where('id', $installment->pinjaman_id)->lockForUpdate()->first();
            if (! $loan) {
                throw new DomainException('Pinjaman tidak ditemukan.');
            }
            if (Schema::hasTable('layanan_lelang') && DB::table('layanan_lelang')->where('pinjaman_id', $loan->id)->exists()) {
                throw new DomainException('Pinjaman dengan penjualan jaminan memerlukan rekonsiliasi menyeluruh; tidak dapat dibatalkan per cicilan.');
            }
            if (Schema::hasTable('layanan_pembayaran') && DB::table('layanan_pembayaran')->where('pinjaman_id', $loan->id)->where('jenis', 'pelunasan')->whereNull('dibatalkan_at')->exists()) {
                throw new DomainException('Pinjaman dengan pelunasan dipercepat memerlukan rekonsiliasi menyeluruh; tidak dapat dibatalkan per cicilan.');
            }
            if (Schema::hasTable('layanan_jaminan_kembali') && DB::table('layanan_jaminan_kembali')->where('pinjaman_id', $loan->id)->where('status', 'diserahkan')->exists()) {
                throw new DomainException('Jaminan sudah diserahkan; gunakan rekonsiliasi menyeluruh.');
            }
            $payment = Schema::hasTable('layanan_pembayaran') ? DB::table('layanan_pembayaran')->where('angsuran_id', $installmentId)->where('jenis', 'angsuran')->whereNull('dibatalkan_at')->lockForUpdate()->latest('id')->first() : null;
            if (Schema::hasTable('layanan_pembayaran') && ! $payment) {
                throw new DomainException('Catatan pembayaran tidak ditemukan atau sudah dibatalkan.');
            }
            // Prefer ref_type; fall back to keterangan (legacy rows / shared transaksi_id space).
            $cashQuery = DB::table('kas')->where('transaksi_id', $installmentId)->where('jenis', 'masuk');
            if (Schema::hasColumn('kas', 'ref_type')) {
                $cashQuery->where(function ($query): void {
                    $query->where('ref_type', CashEntry::ANGSURAN)->orWhereNull('ref_type');
                });
            }
            $cash = $cashQuery
                ->where(function ($query) use ($installment, $loan): void {
                    $query->where('keterangan', 'like', 'Pembayaran angsuran ke-'.$installment->angsuran_ke.' '.$loan->kode_pinjaman.'%')
                        ->orWhere('keterangan', 'like', 'Pembayaran angsuran%')
                        ->orWhere('keterangan', 'Pembayaran');
                })
                ->where('keterangan', 'not like', 'Pembayaran denda%')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();
            if (! $cash) {
                throw new DomainException('Mutasi kas pembayaran tidak ditemukan.');
            }
            DB::table('kas')->orderByDesc('id')->limit(1)->lockForUpdate()->get();
            $before = (float) DB::table('kas')->selectRaw("COALESCE(SUM(CASE WHEN jenis='masuk' THEN jumlah ELSE -jumlah END),0) as saldo")->value('saldo');
            DB::table('kas')->insert(CashEntry::attributes([
                'transaksi_id' => $installmentId,
                'jenis' => 'keluar',
                'jumlah' => $cash->jumlah,
                'saldo_sebelum' => $before,
                'saldo_sesudah' => $before - (float) $cash->jumlah,
                'keterangan' => 'Pembatalan angsuran ke-'.$installment->angsuran_ke.' '.$loan->kode_pinjaman.' — '.$reason,
                'tanggal' => now(),
            ], CashEntry::ANGSURAN_REVERSAL));
            if ($payment) {
                DB::table('layanan_pembayaran')->where('id', $payment->id)->update(['dibatalkan_at' => now()]);
            }
            DB::table('angsuran')->where('id', $installmentId)->update(['status' => 'belum_bayar', 'tanggal_bayar' => null, 'cara_bayar' => 'cicilan', 'denda' => 0, 'keterlambatan_hari' => 0]);
            DB::table('pinjaman')->where('id', $loan->id)->update(['status' => 'aktif', 'sisa_pokok' => (float) $loan->sisa_pokok + (float) $installment->pokok, 'tanggal_lunas' => null]);
            if (Schema::hasTable('pengajuan_pembatalan_angsuran')) {
                DB::table('pengajuan_pembatalan_angsuran')->where('angsuran_id', $installmentId)->where('status', 'menunggu')->update([
                    'status' => 'disetujui',
                    'aktif' => null,
                    'admin_id' => $admin->id,
                    'catatan_admin' => 'Dibatalkan langsung oleh Admin.',
                    'tanggal_keputusan' => now(),
                ]);
            }
            $this->cashLedger->recalculateLocked();

            return ['loan_id' => (int) $loan->id, 'amount' => (float) $cash->jumlah];
        }, 3);
    }
}
