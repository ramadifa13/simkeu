<?php

namespace App\Actions\Loans;

use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Teller/Admin submits a collateral return request after the loan is paid off. */
class RequestCollateralReturnAction
{
    public function handle(int $loanId, User $actor, string $reason, string $collateralProof, string $settlementProof): void
    {
        DB::transaction(function () use ($loanId, $actor, $reason, $collateralProof, $settlementProof): void {
            $loan = DB::table('pinjaman')->where('id', $loanId)->lockForUpdate()->first();
            if (! $loan || $loan->status !== 'lunas') {
                throw new DomainException('Pengembalian jaminan hanya untuk pinjaman yang sudah lunas.');
            }

            $collateral = DB::table('jaminan_pinjaman')->where('pinjaman_id', $loanId)->lockForUpdate()->first();
            if (! $collateral || $collateral->status_jaminan !== 'dititipkan') {
                throw new DomainException('Tidak ada jaminan titipan yang dapat diajukan pengembaliannya.');
            }
            if (blank($collateral->foto_barang ?? null)) {
                throw new DomainException('Foto barang jaminan pada pencairan belum tersedia.');
            }
            if (strlen(trim($reason)) < 5) {
                throw new DomainException('Alasan pengajuan minimal 5 karakter.');
            }

            $existing = DB::table('layanan_jaminan_kembali')->where('pinjaman_id', $loanId)->lockForUpdate()->first();
            if ($existing && in_array($existing->status, ['menunggu', 'pengecekan', 'disetujui', 'diserahkan'], true)) {
                throw new DomainException('Pengajuan pengembalian jaminan sudah ada dan masih diproses.');
            }

            $payload = [
                'user_id' => $actor->id,
                'status' => 'menunggu',
                'admin_id' => null,
                'alasan' => trim($reason),
                'bukti_jaminan' => $collateralProof,
                'bukti_lunas' => $settlementProof,
                'catatan_admin' => null,
                'diperiksa_at' => null,
                'foto' => null,
                'ttd' => null,
                'ttd_teller' => null,
                'diserahkan_at' => null,
            ];

            $existing
                ? DB::table('layanan_jaminan_kembali')->where('id', $existing->id)->update($payload)
                : DB::table('layanan_jaminan_kembali')->insert(['pinjaman_id' => $loanId, ...$payload]);
        }, 3);
    }
}
