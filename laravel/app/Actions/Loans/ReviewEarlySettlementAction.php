<?php

namespace App\Actions\Loans;

use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class ReviewEarlySettlementAction
{
    public function handle(int $loanId, string $decision, float $discount, User $admin, string $note = ''): void
    {
        if (! in_array($decision, ['disetujui', 'ditolak'], true) || $discount < 0 || $discount > 100) {
            throw new DomainException('Keputusan atau diskon tidak valid.');
        }
        DB::transaction(function () use ($loanId, $decision, $discount, $admin, $note): void {
            $request = DB::table('layanan_pelunasan')->where('pinjaman_id', $loanId)->lockForUpdate()->first();
            if (! $request || $request->status !== 'menunggu') {
                throw new DomainException('Tidak ada pengajuan pelunasan yang menunggu.');
            }
            DB::table('layanan_pelunasan')->where('id', $request->id)->update(['status' => $decision, 'diskon_persen' => $decision === 'disetujui' ? $discount : 0, 'admin_id' => $admin->id, 'diputuskan_at' => now(), 'catatan' => $note]);
        }, 3);
    }
}
