<?php

namespace App\Actions\Members;

use App\Models\Anggota;

class GenerateMemberCodes
{
    /**
     * Must be called inside the same database transaction as member creation.
     *
     * @return array{kode_anggota: string}
     */
    public function handle(string $category = 'biasa'): array
    {
        return [
            'kode_anggota' => $this->next($category === 'jaminan' ? 'AGTJ' : 'AGT'),
        ];
    }

    /** First gadai badge for an ordinary AGT member; call inside the loan application transaction. */
    public function nextCollateralBadge(): string
    {
        return $this->next('AGTJ', 'kode_anggota_jaminan');
    }

    private function next(string $prefix, string $column = 'kode_anggota'): string
    {
        $codes = Anggota::query()
            ->lockForUpdate()
            ->where($column, 'like', $prefix.'%')
            ->pluck($column);

        $highest = $codes
            ->filter(fn ($code): bool => is_string($code) && (bool) preg_match('/^'.preg_quote($prefix, '/').'\d+$/', $code))
            ->map(fn (string $code): int => (int) substr($code, strlen($prefix)))
            ->max() ?? 0;

        return $prefix.str_pad((string) ($highest + 1), 4, '0', STR_PAD_LEFT);
    }
}
