<?php

namespace App\Actions\Loans;

use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Admin marks collateral return for inspection, approves, or rejects. */
class ReviewCollateralReturnAction
{
    public function handle(int $loanId, string $decision, User $admin, string $note): void
    {
        DB::transaction(function () use ($loanId, $decision, $admin, $note): void {
            if (! in_array($decision, ['pengecekan', 'disetujui', 'ditolak'], true)) {
                throw new DomainException('Keputusan pengembalian jaminan tidak valid.');
            }
            if (strlen(trim($note)) < 5) {
                throw new DomainException('Catatan Admin minimal 5 karakter.');
            }

            $loan = DB::table('pinjaman')->where('id', $loanId)->lockForUpdate()->first();
            if (! $loan || $loan->status !== 'lunas') {
                throw new DomainException('Pinjaman harus berstatus lunas.');
            }

            $request = DB::table('layanan_jaminan_kembali')->where('pinjaman_id', $loanId)->lockForUpdate()->first();
            if (! $request || ! in_array($request->status, ['menunggu', 'pengecekan'], true)) {
                throw new DomainException('Tidak ada pengajuan pengembalian jaminan yang menunggu keputusan.');
            }

            if ($decision === 'pengecekan' && $request->status === 'pengecekan') {
                throw new DomainException('Pengajuan sudah berada pada tahap pengecekan.');
            }

            DB::table('layanan_jaminan_kembali')->where('id', $request->id)->update([
                'status' => $decision,
                'admin_id' => $admin->id,
                'catatan_admin' => trim($note),
                'diperiksa_at' => now(),
            ]);
        }, 3);
    }
}
