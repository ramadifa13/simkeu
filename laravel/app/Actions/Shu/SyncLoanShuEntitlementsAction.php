<?php

namespace App\Actions\Shu;

use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Ports native shuCatatHakPinjaman / shuSinkronkanHakLama. */
class SyncLoanShuEntitlementsAction
{
    public function __construct(private readonly ShuSupport $support) {}

    public function handle(?int $loanId, User $actor): int
    {
        $this->support->requireAdmin($actor);
        if (! Schema::hasTable('shu_hak_pinjaman') || ! Schema::hasColumn('anggota', 'kategori_shu')) {
            throw new DomainException('Modul SHU pinjaman belum tersedia.');
        }

        return DB::transaction(function () use ($loanId): int {
            $query = DB::table('pinjaman as p')
                ->join('jenis_pinjaman as jp', 'jp.id', '=', 'p.jenis_pinjaman_id')
                ->where('p.status', 'lunas')
                ->where('jp.memerlukan_jaminan', 0)
                ->orderBy('p.id');
            if ($loanId) {
                $query->where('p.id', $loanId);
            }
            $ids = $query->pluck('p.id');
            foreach ($ids as $id) {
                $this->record((int) $id);
            }

            return $ids->count();
        }, 3);
    }

    private function record(int $loanId): void
    {
        $loan = DB::table('pinjaman as p')
            ->join('jenis_pinjaman as jp', 'jp.id', '=', 'p.jenis_pinjaman_id')
            ->join('anggota as a', 'a.id', '=', 'p.anggota_id')
            ->where('p.id', $loanId)
            ->select(['p.*', 'jp.memerlukan_jaminan', 'a.kode_anggota'])
            ->lockForUpdate()
            ->first();
        if (! $loan || $loan->status !== 'lunas' || $loan->memerlukan_jaminan) {
            return;
        }
        $category = (($loan->kategori_shu ?? 'anggota') === 'ketua_kelompok') ? 'ketua_kelompok' : 'anggota';
        $percent = $category === 'ketua_kelompok' ? 2.0 : 1.0;
        $amount = (int) round((float) $loan->jumlah_pinjaman * $percent / 100, 0, PHP_ROUND_HALF_UP);
        $onTime = $this->onTime($loanId);
        if ($amount > 0) {
            DB::table('shu_hak_pinjaman')->insertOrIgnore([
                'pinjaman_id' => $loanId,
                'anggota_id' => $loan->anggota_id,
                'sumber_anggota_id' => $loan->anggota_id,
                'jenis' => 'pelunasan',
                'kategori_snapshot' => $category,
                'dasar_pinjaman' => $loan->jumlah_pinjaman,
                'persen' => $percent,
                'jumlah' => $amount,
                'pembayaran_lancar' => $onTime ? 1 : 0,
                'status_bayar' => 'belum',
                'created_at' => now(),
            ]);
        }
        $leaderId = (int) ($loan->ketua_kelompok_id ?? 0);
        if ($category === 'anggota' && $leaderId > 0 && $onTime) {
            $isLeader = DB::table('anggota')->where('id', $leaderId)->where('kategori_shu', 'ketua_kelompok')->exists();
            if ($isLeader) {
                $refPercent = 0.25;
                $refAmount = (int) round((float) $loan->jumlah_pinjaman * $refPercent / 100, 0, PHP_ROUND_HALF_UP);
                if ($refAmount > 0) {
                    DB::table('shu_hak_pinjaman')->insertOrIgnore([
                        'pinjaman_id' => $loanId,
                        'anggota_id' => $leaderId,
                        'sumber_anggota_id' => $loan->anggota_id,
                        'jenis' => 'referensi',
                        'kategori_snapshot' => 'ketua_kelompok',
                        'dasar_pinjaman' => $loan->jumlah_pinjaman,
                        'persen' => $refPercent,
                        'jumlah' => $refAmount,
                        'pembayaran_lancar' => 1,
                        'status_bayar' => 'belum',
                        'created_at' => now(),
                    ]);
                }
            }
        }
    }

    private function onTime(int $loanId): bool
    {
        $total = (int) DB::table('angsuran')->where('pinjaman_id', $loanId)->count();
        $bad = (int) DB::table('angsuran')->where('pinjaman_id', $loanId)
            ->whereRaw("(status <> 'lunas' OR tanggal_bayar IS NULL OR DATE(tanggal_bayar) > tanggal_jatuh_tempo)")
            ->count();

        return $total > 0 && $bad === 0;
    }
}
