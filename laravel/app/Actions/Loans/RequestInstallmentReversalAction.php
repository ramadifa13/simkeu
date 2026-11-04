<?php

namespace App\Actions\Loans;

use App\Models\User;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Records a Teller request only; cash is changed later by an Admin decision. */
class RequestInstallmentReversalAction
{
    public function handle(int $loanId, int $installmentId, User $teller, string $reason): void
    {
        if ($teller->role !== 'teller') {
            throw new DomainException('Pengajuan pembatalan pembayaran hanya dapat dibuat oleh Teller.');
        }
        if (! Schema::hasTable('pengajuan_pembatalan_angsuran')) {
            throw new DomainException('Tabel pengajuan pembatalan angsuran belum tersedia.');
        }

        DB::transaction(function () use ($loanId, $installmentId, $teller, $reason): void {
            $installment = DB::table('angsuran')->where('id', $installmentId)->where('pinjaman_id', $loanId)->lockForUpdate()->first();
            if (! $installment || $installment->status !== 'lunas' || $installment->cara_bayar === 'pelunasan') {
                throw new DomainException('Hanya pembayaran angsuran biasa yang telah lunas yang dapat diajukan pembatalannya.');
            }
            if (! $installment->tanggal_bayar || ! Carbon::parse($installment->tanggal_bayar)->isToday()) {
                throw new DomainException('Teller hanya dapat mengajukan pembatalan pembayaran yang dicatat hari ini.');
            }

            $loan = DB::table('pinjaman')->where('id', $loanId)->lockForUpdate()->first();
            if (! $loan || $loan->status === 'lunas') {
                throw new DomainException('Pinjaman yang sudah lunas tidak dapat diajukan pembatalan angsurannya.');
            }
            if (DB::table('pengajuan_pembatalan_angsuran')->where('angsuran_id', $installmentId)->where('status', 'menunggu')->lockForUpdate()->exists()) {
                throw new DomainException('Pengajuan pembatalan untuk angsuran ini masih menunggu keputusan Admin.');
            }

            DB::table('pengajuan_pembatalan_angsuran')->insert([
                'angsuran_id' => $installmentId,
                'teller_id' => $teller->id,
                'alasan' => trim($reason),
                'tanggal_pengajuan' => now(),
                'status' => 'menunggu',
                'aktif' => 1,
                'dilihat_teller' => 0,
            ]);
        }, 3);
    }
}
