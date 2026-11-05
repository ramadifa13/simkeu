<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;

/** Discriminator for kas.transaksi_id (shared across domains). */
final class CashEntry
{
    public const ANGSURAN = 'angsuran';

    public const ANGSURAN_DENDA = 'angsuran_denda';

    public const ANGSURAN_REVERSAL = 'angsuran_reversal';

    public const PINJAMAN_CAIR = 'pinjaman_cair';

    public const PINJAMAN_LUNAS = 'pinjaman_lunas';

    public const PINJAMAN_LUNAS_REVERSAL = 'pinjaman_lunas_reversal';

    public const TRANSAKSI = 'transaksi';

    public const WASERDA = 'waserda';

    public const LAYANAN = 'layanan';

    public const SHU = 'shu';

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function attributes(array $row, string $refType): array
    {
        if (Schema::hasColumn('kas', 'ref_type')) {
            $row['ref_type'] = $refType;
        }

        return $row;
    }
}
