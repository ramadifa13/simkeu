<?php

namespace App\Actions\Loans;

use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class ReviewLoanApplicationAction
{
    public function handle(int $applicationId, string $decision, ?string $note, User $admin): void
    {
        if (! in_array($decision, ['disetujui', 'ditolak'], true)) {
            throw new DomainException('Keputusan pengajuan tidak valid.');
        }
        DB::transaction(function () use ($applicationId, $decision, $note, $admin): void {
            $application = DB::table('pengajuan_pinjaman')->where('id', $applicationId)->lockForUpdate()->first();
            if (! $application || $application->status !== 'menunggu') {
                throw new DomainException('Pengajuan sudah diputuskan atau tidak ditemukan.');
            }
            DB::table('pengajuan_pinjaman')->where('id', $applicationId)->update(['status' => $decision, 'catatan_admin' => $note, 'admin_id' => $admin->id, 'tanggal_persetujuan' => now()]);
        }, 3);
    }
}
