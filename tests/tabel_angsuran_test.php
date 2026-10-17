<?php
/**
 * Uji tabel angsuran resmi dari klien (Okt 2026) terhadap hitungPinjaman().
 * Jalankan: php tests/tabel_angsuran_test.php   (exit code 0 = semua cocok)
 */
require __DIR__ . '/../includes/pinjaman_helper.php';

// pinjaman => [tenor bulan, bunga %, jumlah cicilan mingguan, angsuran per minggu]
$tabel_klien = [
    [1000000, 3, 35, 12, 112500], [1000000, 2, 25, 8, 156250], [1000000, 1, 15, 4, 287500],
    [2000000, 3, 35, 12, 225000], [2000000, 2, 25, 8, 312500], [2000000, 1, 15, 4, 575000],
    [3000000, 3, 35, 12, 337500], [3000000, 2, 25, 8, 468750], [3000000, 1, 15, 4, 862500],
];

$gagal = 0;
foreach ($tabel_klien as $k) {
    list($pokok, $tenor, $bunga, $n, $angsuran) = $k;
    $h = hitungPinjaman($pokok, $bunga, $tenor, 'mingguan', 'flat');
    $total_cicilan = array_sum(array_column($h['rincian'], 'jumlah'));
    $ok = $h['jumlah_cicilan'] === $n && (int) $h['angsuran'] === $angsuran && (int) $total_cicilan === (int) $h['total'];
    printf("%s  Rp %9s  %d bln %2d%%  %2dx @ %s  (hitung: %dx @ %s, total %s)\n",
        $ok ? 'OK   ' : 'GAGAL', number_format($pokok, 0, ',', '.'), $tenor, $bunga, $n,
        number_format($angsuran, 0, ',', '.'), $h['jumlah_cicilan'], number_format($h['angsuran'], 0, ',', '.'),
        number_format($h['total'], 0, ',', '.'));
    $gagal += $ok ? 0 : 1;
}

// Lampiran klien: rincian 12 minggu untuk Rp 1.000.000, 35%
$h = hitungPinjaman(1000000, 35, 3, 'mingguan', 'flat');
$sisa = 1000000;
$ok = true;
foreach ($h['rincian'] as $r) {
    $sisa -= $r['pokok'];
    $diharapkan_pokok = $r['ke'] < 12 ? 83333 : 83337;
    $diharapkan_bunga = $r['ke'] < 12 ? 29167 : 29163;
    if ((int) $r['pokok'] !== $diharapkan_pokok || (int) $r['bunga'] !== $diharapkan_bunga || (int) $r['jumlah'] !== 112500) {
        $ok = false;
    }
}
$ok = $ok && (int) $sisa === 0 && (int) $h['total_bunga'] === 350000;
echo ($ok ? 'OK   ' : 'GAGAL') . "  Lampiran 12 minggu (pokok 83.333/83.337, bunga 29.167/29.163, total 112.500, sisa 0)\n";
$gagal += $ok ? 0 : 1;

echo $gagal === 0 ? "\nSemua cocok dengan tabel klien.\n" : "\n$gagal kasus TIDAK cocok.\n";
exit($gagal === 0 ? 0 : 1);
