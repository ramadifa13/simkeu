<?php

namespace App\Actions\Loans;

use App\Actions\Cash\RecalculateCashLedgerAction;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ReverseSettlementAction
{
    public function __construct(private readonly RecalculateCashLedgerAction $cashLedger) {}

    public function handle(int $loanId, User $admin, string $reason): float
    {
        return DB::transaction(function () use ($loanId, $reason): float {
            if (mb_strlen(trim($reason)) < 5) {
                throw new DomainException('Alasan pembatalan minimal 5 karakter.');
            }
            $loan = DB::table('pinjaman')->where('id', $loanId)->lockForUpdate()->first();
            if (! $loan || $loan->status !== 'lunas') {
                throw new DomainException('Hanya pinjaman lunas yang dapat direversal.');
            }
            if (Schema::hasTable('layanan_jaminan_kembali') && DB::table('layanan_jaminan_kembali')->where('pinjaman_id', $loanId)->where('status', 'diserahkan')->exists()) {
                throw new DomainException('Jaminan sudah diserahkan; lakukan rekonsiliasi jaminan terlebih dahulu.');
            }
            $payment = DB::table('layanan_pembayaran')->where('pinjaman_id', $loanId)->where('jenis', 'pelunasan')->whereNull('dibatalkan_at')->lockForUpdate()->latest('id')->first();
            if (! $payment) {
                throw new DomainException('Catatan pelunasan aktif tidak ditemukan.');
            }
            $cash = DB::table('kas')->where('transaksi_id', $loanId)->where('jenis', 'masuk')->where('keterangan', 'like', 'Pelunasan%')->lockForUpdate()->latest('id')->first();
            if (! $cash) {
                throw new DomainException('Mutasi kas pelunasan tidak ditemukan.');
            }
            $rows = DB::table('angsuran')->where('pinjaman_id', $loanId)->where('cara_bayar', 'pelunasan')->lockForUpdate()->get();
            if ($rows->isEmpty()) {
                throw new DomainException('Jadwal pelunasan tidak ditemukan.');
            }
            DB::table('kas')->orderByDesc('id')->limit(1)->lockForUpdate()->get();
            $before = (float) DB::table('kas')->selectRaw("COALESCE(SUM(CASE WHEN jenis='masuk' THEN jumlah ELSE -jumlah END),0) as saldo")->value('saldo');
            DB::table('kas')->insert(['transaksi_id' => $loanId, 'jenis' => 'keluar', 'jumlah' => $cash->jumlah, 'saldo_sebelum' => $before, 'saldo_sesudah' => $before - (float) $cash->jumlah, 'keterangan' => 'Reversal pelunasan '.$loan->kode_pinjaman.' — '.$reason, 'tanggal' => now()]);
            DB::table('layanan_pembayaran')->where('id', $payment->id)->update(['dibatalkan_at' => now()]);
            foreach ($rows as $row) {
                DB::table('angsuran')->where('id', $row->id)->update(['status' => 'belum_bayar', 'tanggal_bayar' => null, 'cara_bayar' => 'cicilan', 'denda' => 0, 'keterlambatan_hari' => 0]);
            }
            DB::table('pinjaman')->where('id', $loanId)->update(['status' => 'aktif', 'sisa_pokok' => (float) $loan->sisa_pokok + (float) $rows->sum('pokok'), 'tanggal_lunas' => null]);
            if (Schema::hasTable('pelunasan_dini')) {
                DB::table('pelunasan_dini')->where('pinjaman_id', $loanId)->delete();
            }
            $this->cashLedger->recalculateLocked();

            return (float) $cash->jumlah;
        }, 3);
    }
}
