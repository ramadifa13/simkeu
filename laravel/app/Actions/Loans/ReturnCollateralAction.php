<?php

namespace App\Actions\Loans;

use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Hands over collateral after Admin approved the return request (BAST). */
class ReturnCollateralAction
{
    public function handle(int $loanId, User $actor, string $photo, string $memberSignature, string $tellerSignature, string $note = '', bool $physicalChecked = false): void
    {
        DB::transaction(function () use ($loanId, $actor, $photo, $memberSignature, $tellerSignature, $note, $physicalChecked): void {
            if (! $physicalChecked) {
                throw new DomainException('Pinjaman harus lunas dan fisik jaminan harus diperiksa.');
            }

            $loan = DB::table('pinjaman')->where('id', $loanId)->lockForUpdate()->first();
            if (! $loan || $loan->status !== 'lunas') {
                throw new DomainException('Jaminan hanya dapat diserahkan setelah pinjaman lunas.');
            }

            $collateral = DB::table('jaminan_pinjaman')->where('pinjaman_id', $loanId)->lockForUpdate()->first();
            if (! $collateral || $collateral->status_jaminan !== 'dititipkan') {
                throw new DomainException('Tidak ada jaminan titipan yang dapat diserahkan.');
            }

            $request = DB::table('layanan_jaminan_kembali')->where('pinjaman_id', $loanId)->lockForUpdate()->first();
            if (! $request || $request->status !== 'disetujui') {
                throw new DomainException('Serah terima hanya dapat dilakukan setelah Admin menyetujui pengajuan pengembalian.');
            }

            DB::table('layanan_jaminan_kembali')->where('id', $request->id)->update([
                'user_id' => $actor->id,
                'status' => 'diserahkan',
                'foto' => $photo,
                'ttd' => $memberSignature,
                'ttd_teller' => $tellerSignature,
                'diserahkan_at' => now(),
                'alasan' => filled($note) ? trim($note) : $request->alasan,
            ]);

            DB::table('jaminan_pinjaman')->where('id', $collateral->id)->update([
                'status_jaminan' => 'dikembalikan',
                'tanggal_dikembalikan' => today()->toDateString(),
            ]);
        }, 3);
    }
}
