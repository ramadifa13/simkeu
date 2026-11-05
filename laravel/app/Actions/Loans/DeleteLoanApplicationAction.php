<?php

namespace App\Actions\Loans;

use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DeleteLoanApplicationAction
{
    public function handle(int $applicationId): void
    {
        DB::transaction(function () use ($applicationId): void {
            $application = DB::table('pengajuan_pinjaman')->where('id', $applicationId)->lockForUpdate()->first();
            if (! $application) {
                throw new DomainException('Pengajuan tidak ditemukan.');
            }
            if (! in_array($application->status, ['menunggu', 'ditolak'], true)) {
                throw new DomainException('Hanya pengajuan menunggu atau ditolak yang dapat dihapus.');
            }
            if (DB::table('pinjaman')->where('pengajuan_id', $applicationId)->exists()) {
                throw new DomainException('Pengajuan sudah terkait pinjaman dan tidak dapat dihapus.');
            }

            if (Schema::hasTable('jaminan_pinjaman')) {
                DB::table('jaminan_pinjaman')->where('pengajuan_id', $applicationId)->delete();
            }

            $deleted = DB::table('pengajuan_pinjaman')
                ->where('id', $applicationId)
                ->whereIn('status', ['menunggu', 'ditolak'])
                ->delete();

            if ($deleted !== 1) {
                throw new DomainException('Gagal menghapus pengajuan.');
            }

            $this->clearUnusedPawnMemberCode((int) $application->anggota_id);
        }, 3);
    }

    private function clearUnusedPawnMemberCode(int $memberId): void
    {
        if ($memberId <= 0 || ! Schema::hasColumn('anggota', 'kode_anggota_jaminan')) {
            return;
        }

        $member = DB::table('anggota')->where('id', $memberId)->lockForUpdate()->first(['id', 'kode_anggota_jaminan']);
        if (! $member || blank($member->kode_anggota_jaminan)) {
            return;
        }

        $hasPawnLoan = DB::table('pinjaman as p')
            ->join('jenis_pinjaman as jp', 'jp.id', '=', 'p.jenis_pinjaman_id')
            ->where('p.anggota_id', $memberId)
            ->where('jp.memerlukan_jaminan', 1)
            ->exists();
        if ($hasPawnLoan) {
            return;
        }

        $hasActivePawnApplication = DB::table('pengajuan_pinjaman as pp')
            ->join('jenis_pinjaman as jp', 'jp.id', '=', 'pp.jenis_pinjaman_id')
            ->where('pp.anggota_id', $memberId)
            ->where('jp.memerlukan_jaminan', 1)
            ->whereIn('pp.status', ['menunggu', 'disetujui'])
            ->exists();
        if ($hasActivePawnApplication) {
            return;
        }

        DB::table('anggota')->where('id', $memberId)->update(['kode_anggota_jaminan' => null]);
    }
}
