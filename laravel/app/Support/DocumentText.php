<?php

namespace App\Support;

/** Formatting helpers mirroring native config/functions.php + pinjaman_helper labels. */
class DocumentText
{
    public static function rupiah(float|int|string|null $amount): string
    {
        if ((float) $amount == 0.0) {
            return 'Rp 0';
        }

        return 'Rp '.number_format((float) $amount, 0, ',', '.');
    }

    public static function dateId(mixed $date, bool $short = false): string
    {
        $raw = trim((string) $date);
        if ($raw === '' || str_starts_with($raw, '0000-00-00')) {
            return '-';
        }

        $timestamp = strtotime($raw);
        if ($timestamp === false) {
            return $raw;
        }

        $months = $short
            ? [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des']
            : [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

        return date('j', $timestamp).' '.$months[(int) date('n', $timestamp)].' '.date('Y', $timestamp);
    }

    public static function frequency(?string $frequency): string
    {
        return match ($frequency) {
            'mingguan' => 'Mingguan',
            'dua_mingguan' => '2 Minggu Sekali',
            default => 'Bulanan',
        };
    }

    public static function period(?string $frequency): string
    {
        return match ($frequency) {
            'mingguan' => 'Minggu',
            'dua_mingguan' => '2 Minggu',
            default => 'Bulan',
        };
    }

    public static function interestLabel(float|int|string|null $rate, ?string $scheme = 'flat'): string
    {
        $angka = rtrim(rtrim(number_format((float) $rate, 2, ',', '.'), '0'), ',');

        return $scheme === 'tahunan' ? $angka.'% / tahun' : $angka.'% flat';
    }

    public static function weeklyPawnFee(float $principal): ?float
    {
        if ($principal < 1_000_000 || fmod($principal, 1_000_000.0) != 0.0) {
            return null;
        }

        return 5000 + ((int) ($principal / 1_000_000) * 5000);
    }

    public static function terbilang(float|int $amount): string
    {
        $x = (int) abs(round($amount));
        $satuan = ['', 'satu', 'dua', 'tiga', 'empat', 'lima', 'enam', 'tujuh', 'delapan', 'sembilan', 'sepuluh', 'sebelas'];
        if ($x < 12) {
            return $satuan[$x];
        }
        if ($x < 20) {
            return self::terbilang($x - 10).' belas';
        }
        if ($x < 100) {
            return self::terbilang(intdiv($x, 10)).' puluh'.($x % 10 ? ' '.self::terbilang($x % 10) : '');
        }
        if ($x < 200) {
            return 'seratus'.($x - 100 ? ' '.self::terbilang($x - 100) : '');
        }
        if ($x < 1000) {
            return self::terbilang(intdiv($x, 100)).' ratus'.($x % 100 ? ' '.self::terbilang($x % 100) : '');
        }
        if ($x < 2000) {
            return 'seribu'.($x - 1000 ? ' '.self::terbilang($x - 1000) : '');
        }
        if ($x < 1_000_000) {
            return self::terbilang(intdiv($x, 1000)).' ribu'.($x % 1000 ? ' '.self::terbilang($x % 1000) : '');
        }
        if ($x < 1_000_000_000) {
            return self::terbilang(intdiv($x, 1_000_000)).' juta'.($x % 1_000_000 ? ' '.self::terbilang($x % 1_000_000) : '');
        }
        if ($x < 1_000_000_000_000) {
            return self::terbilang(intdiv($x, 1_000_000_000)).' miliar'.($x % 1_000_000_000 ? ' '.self::terbilang($x % 1_000_000_000) : '');
        }

        return self::terbilang(intdiv($x, 1_000_000_000_000)).' triliun'.($x % 1_000_000_000_000 ? ' '.self::terbilang($x % 1_000_000_000_000) : '');
    }

    public static function terbilangRupiah(float|int $amount): string
    {
        $kata = (int) round($amount) === 0 ? 'nol' : self::terbilang($amount);

        return ucfirst($kata).' rupiah';
    }

    public static function fillTemplate(string $template, array $map): string
    {
        return strtr($template, $map);
    }
}
