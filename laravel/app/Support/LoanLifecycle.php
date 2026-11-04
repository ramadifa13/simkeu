<?php

namespace App\Support;

/** Open (collectible) loan statuses shared by payment and collection flows. */
class LoanLifecycle
{
    /** @return list<string> */
    public static function collectibleStatuses(): array
    {
        return ['aktif', 'macet'];
    }

    public static function isCollectible(?string $status): bool
    {
        return in_array((string) $status, self::collectibleStatuses(), true);
    }

    /** Native layanan KPI: telat ≥ 7 hari dianggap macet. */
    public static function overdueDaysForMacet(): int
    {
        return 7;
    }
}
