<?php

namespace App\Actions\Loans;

use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class CompleteDisbursementPhotosAction
{
    /**
     * @param  array{foto_nasabah:string,foto_barang?:?string,foto_nasabah_barang?:?string}  $photos
     */
    public function handle(int $loanId, User $actor, array $photos): string
    {
        return DB::transaction(function () use ($loanId, $photos): string {
            $loan = DB::table('pinjaman as p')
                ->join('jenis_pinjaman as jp', 'jp.id', '=', 'p.jenis_pinjaman_id')
                ->where('p.id', $loanId)
                ->lockForUpdate()
                ->first(['p.*', 'jp.memerlukan_jaminan']);

            if (! $loan) {
                throw new DomainException('Pinjaman tidak ditemukan.');
            }

            $fotoNasabah = trim((string) ($photos['foto_nasabah'] ?? ''));
            if ($fotoNasabah === '') {
                throw new DomainException('Foto nasabah saat pencairan wajib diunggah.');
            }

            $requiresCollateral = (bool) $loan->memerlukan_jaminan;
            $fotoBarang = trim((string) ($photos['foto_barang'] ?? ''));
            $fotoNasabahBarang = trim((string) ($photos['foto_nasabah_barang'] ?? ''));
            $oldPhoto = Schema::hasColumn('pinjaman', 'foto_pencairan') ? (string) ($loan->foto_pencairan ?? '') : '';

            if (Schema::hasColumn('pinjaman', 'foto_pencairan')) {
                DB::table('pinjaman')->where('id', $loanId)->update(['foto_pencairan' => $fotoNasabah]);
            }

            if ($requiresCollateral && ($fotoBarang !== '' || $fotoNasabahBarang !== '')) {
                $collateral = DB::table('jaminan_pinjaman')->where('pinjaman_id', $loanId)->lockForUpdate()->first();
                if (! $collateral) {
                    $pengajuanId = (int) ($loan->pengajuan_id ?? 0);
                    if ($pengajuanId <= 0) {
                        throw new DomainException('Pengajuan pinjaman tidak ditemukan; tidak dapat menyimpan foto jaminan.');
                    }
                    DB::table('jaminan_pinjaman')->insert([
                        'pengajuan_id' => $pengajuanId,
                        'pinjaman_id' => $loanId,
                        'jenis_barang' => 'Dilengkapi migrasi',
                        'identitas_barang' => '-',
                        'deskripsi_barang' => 'Foto jaminan dilengkapi setelah pencairan/migrasi',
                        'nilai_taksiran' => 0,
                        'foto_barang' => $fotoBarang !== '' ? $fotoBarang : null,
                        'foto_nasabah_barang' => $fotoNasabahBarang !== '' ? $fotoNasabahBarang : null,
                        'status_jaminan' => 'dititipkan',
                    ]);
                } else {
                    $update = [];
                    if ($fotoBarang !== '') {
                        $update['foto_barang'] = $fotoBarang;
                    }
                    if ($fotoNasabahBarang !== '') {
                        $update['foto_nasabah_barang'] = $fotoNasabahBarang;
                    }
                    if ($update !== []) {
                        DB::table('jaminan_pinjaman')->where('id', $collateral->id)->update($update);
                    }
                }
            }

            if ($oldPhoto !== '' && $oldPhoto !== $fotoNasabah) {
                Storage::disk('local')->delete($oldPhoto);
            }

            return $fotoNasabah;
        }, 3);
    }
}
