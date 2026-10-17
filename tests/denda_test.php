<?php
/**
 * Uji aturan denda klien (Okt 2026): nominal per hari menurut besar pinjaman, dihitung mulai
 * hari setelah jatuh tempo sampai jatuh tempo cicilan berikutnya.
 * Jalankan: php tests/denda_test.php   (exit code 0 = semua lulus)
 */
require __DIR__ . '/../includes/denda_helper.php';

$gagal = 0;
function cek($nama, $diharapkan, $hasil) {
    global $gagal;
    $ok = (float) $diharapkan === (float) $hasil;
    printf("%s  %s  (harapan %s, hasil %s)\n", $ok ? 'OK   ' : 'GAGAL', $nama, $diharapkan, $hasil);
    if (!$ok) {
        $gagal++;
    }
}

// per_hari, hari_telat, batas_hari (hari ke jatuh tempo berikutnya), denda
$kasus = array(
    array('Rp 1 jt, telat 1 hari',            1500, 1, 7, 1500),
    array('Rp 1 jt, telat 3 hari',            1500, 3, 7, 4500),
    array('Rp 2 jt, telat 2 hari',            2500, 2, 7, 5000),
    array('Rp 3 jt, telat 4 hari',            3500, 4, 7, 14000),
    array('Belum telat (0 hari)',             1500, 0, 7, 0),
    array('Belum jatuh tempo (-2 hari)',      1500, -2, 7, 0),
    array('Mingguan: telat 20 hari, dibatasi 7 hari (sampai jatuh tempo berikutnya)', 1500, 20, 7, 10500),
    array('Bulanan: telat 40 hari, dibatasi 31 hari', 2500, 40, 31, 77500),
    array('Cicilan terakhir (tanpa batas): telat 20 hari', 3500, 20, null, 70000),
);
foreach ($kasus as $k) {
    $h = dendaDariParameter($k[1], $k[2], $k[3]);
    cek($k[0], $k[4], $h['denda']);
}

echo $gagal === 0 ? "\nSemua lulus.\n" : "\n$gagal kasus GAGAL.\n";
exit($gagal === 0 ? 0 : 1);
