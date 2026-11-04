<?php

namespace App\Actions\Loans;

use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class RequestEarlySettlementAction
{
    public function handle(int $loanId, User $actor, string $note = ''): void
    {
        DB::transaction(function () use ($loanId, $actor, $note): void {
            $loan = DB::table('pinjaman')->where('id', $loanId)->lockForUpdate()->first();
            if (! $loan || ! \App\Support\LoanLifecycle::isCollectible($loan->status)) {
                throw new DomainException('Hanya pinjaman aktif yang dapat diajukan untuk pelunasan dipercepat.');
            }
            $existing = DB::table('layanan_pelunasan')->where('pinjaman_id', $loanId)->lockForUpdate()->first();
            if ($existing && $existing->status !== 'ditolak') {
                throw new DomainException('Pengajuan pelunasan untuk pinjaman ini masih diproses atau sudah disetujui.');
            }
            $data = ['user_id' => $actor->id, 'status' => 'menunggu', 'diskon_persen' => 0, 'admin_id' => null, 'diputuskan_at' => null, 'catatan' => $note, 'created_at' => now()];
            $existing ? DB::table('layanan_pelunasan')->where('id', $existing->id)->update($data) : DB::table('layanan_pelunasan')->insert(['pinjaman_id' => $loanId, ...$data]);
        }, 3);
    }
}
