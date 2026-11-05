<?php

namespace App\Actions\Members;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror native fotoNasabahAnggota / fotoNasabahAnggotaMap:
 * prefer active loan (oldest disbursement), else latest paid loan with foto_pencairan.
 */
class ResolveMemberDisbursementPhoto
{
    public function handle(?int $memberId): ?string
    {
        if ($memberId === null || $memberId <= 0) {
            return null;
        }

        return $this->map([$memberId])[$memberId] ?? null;
    }

    /**
     * @param  list<int>  $memberIds
     * @return array<int, string>
     */
    public function map(array $memberIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $memberIds))));
        if ($ids === [] || ! Schema::hasTable('pinjaman') || ! Schema::hasColumn('pinjaman', 'foto_pencairan')) {
            return [];
        }

        $rows = DB::table('pinjaman')
            ->whereIn('anggota_id', $ids)
            ->whereNotNull('foto_pencairan')
            ->where('foto_pencairan', '<>', '')
            ->orderBy('anggota_id')
            ->orderByRaw("CASE WHEN status <> 'lunas' THEN 0 ELSE 1 END")
            ->orderByRaw("CASE WHEN status <> 'lunas' THEN tanggal_pencairan END ASC")
            ->orderByRaw("CASE WHEN status <> 'lunas' THEN id END ASC")
            ->orderByRaw("CASE WHEN status = 'lunas' THEN tanggal_pencairan END DESC")
            ->orderByRaw("CASE WHEN status = 'lunas' THEN id END DESC")
            ->get(['anggota_id', 'foto_pencairan']);

        $map = [];
        foreach ($rows as $row) {
            $memberId = (int) $row->anggota_id;
            if (isset($map[$memberId])) {
                continue;
            }
            $path = trim((string) $row->foto_pencairan);
            if ($path !== '' && ! str_contains($path, '..') && str_starts_with($path, 'pencairan/')) {
                $map[$memberId] = $path;
            }
        }

        return $map;
    }
}
