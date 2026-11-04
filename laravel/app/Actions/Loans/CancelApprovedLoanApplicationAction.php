<?php

namespace App\Actions\Loans;

use DomainException;
use Illuminate\Support\Facades\DB;

class CancelApprovedLoanApplicationAction
{
    public function handle(int $applicationId, string $reason): void
    {
        DB::transaction(function () use ($applicationId, $reason): void {
            $application = DB::table('pengajuan_pinjaman')->where('id', $applicationId)->lockForUpdate()->first();
            if (! $application || $application->status !== 'disetujui') {
                throw new DomainException('Hanya pengajuan yang telah disetujui dan belum dicairkan dapat dibatalkan.');
            }
            if (DB::table('pinjaman')->where('pengajuan_id', $applicationId)->exists()) {
                throw new DomainException('Pengajuan telah memiliki pinjaman dan tidak dapat dibatalkan.');
            }

            DB::table('pengajuan_pinjaman')->where('id', $applicationId)->update([
                'status' => 'batal_cair',
                'catatan_admin' => $reason,
            ]);
        }, 3);
    }
}
