<?php

namespace App\Actions\Loans;

use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** An Admin decision either rejects a request or atomically creates its compensating payment reversal. */
class ReviewInstallmentReversalRequestAction
{
    public function __construct(private readonly ReverseInstallmentPaymentAction $reverse) {}

    public function handle(int $loanId, int $requestId, string $decision, User $admin, string $note = ''): array
    {
        if ($admin->role !== 'admin' || ! in_array($decision, ['disetujui', 'ditolak'], true)) {
            throw new DomainException('Keputusan pembatalan tidak valid.');
        }

        return DB::transaction(function () use ($loanId, $requestId, $decision, $admin, $note): array {
            $request = DB::table('pengajuan_pembatalan_angsuran as r')
                ->join('angsuran as a', 'a.id', '=', 'r.angsuran_id')
                ->where('r.id', $requestId)->where('a.pinjaman_id', $loanId)
                ->select('r.*')->lockForUpdate()->first();
            if (! $request || $request->status !== 'menunggu') {
                throw new DomainException('Pengajuan pembatalan tidak ditemukan atau sudah diputuskan.');
            }

            $result = ['loan_id' => $loanId, 'amount' => 0.0];
            if ($decision === 'disetujui') {
                $result = $this->reverse->handle((int) $request->angsuran_id, $admin, $request->alasan);
            }

            $update = [
                'status' => $decision,
                'aktif' => null,
                'admin_id' => $admin->id,
                'catatan_admin' => trim($note) ?: null,
                'tanggal_keputusan' => now(),
            ];
            if (Schema::hasColumn('pengajuan_pembatalan_angsuran', 'dilihat_teller')) {
                $update['dilihat_teller'] = 0;
            }
            DB::table('pengajuan_pembatalan_angsuran')->where('id', $requestId)->update($update);

            return $result;
        }, 3);
    }
}
