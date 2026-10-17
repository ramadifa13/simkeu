<?php
require_once __DIR__ . '/../includes/pinjaman_helper.php';

function pastikan($kondisi, $pesan) {
    if (!$kondisi) {
        fwrite(STDERR, "GAGAL: $pesan\n");
        exit(1);
    }
}

$h = hitungPinjaman(2000000, 45, 6, 'dua_mingguan', 'flat');
pastikan($h['jumlah_cicilan'] === 12, 'tenor 6 bulan harus menghasilkan 12 cicilan');
pastikan($h['total'] === 2900000.0, 'total pinjaman Rp 2 juta harus Rp 2,9 juta');
pastikan($h['angsuran'] === 241667.0, 'angsuran reguler Rp 2 juta harus Rp 241.667');
pastikan(array_sum(array_column($h['rincian'], 'jumlah')) === 2900000.0, 'jumlah seluruh cicilan tidak sama dengan total');
pastikan($h['rincian'][11]['jumlah'] === 241663.0, 'cicilan terakhir Rp 2 juta harus Rp 241.663');
pastikan($h['rincian'][11]['pokok'] === 166663.0, 'koreksi pembulatan pokok terakhir harus menjaga total pokok tepat Rp 2 juta');
pastikan(array_sum(array_column($h['rincian'], 'pokok')) === 2000000.0, 'jumlah komponen pokok harus tepat Rp 2 juta');
pastikan(hitungJatuhTempo('2026-10-17', 1, 'dua_mingguan') === '2026-10-31', 'cicilan pertama bukan +14 hari');
pastikan(hitungJatuhTempo('2026-10-17', 12, 'dua_mingguan') === '2027-04-03', 'cicilan ke-12 bukan +168 hari');
pastikan(hitungJatuhTempo('2026-10-15', 1, 'dua_mingguan') === '2026-10-29', 'jadwal contoh cicilan pertama harus 29 Okt 2026');
pastikan(hitungJatuhTempo('2026-10-15', 12, 'dua_mingguan') === '2027-04-01', 'jadwal contoh cicilan terakhir harus 1 Apr 2027');
pastikan(labelFrekuensi('dua_mingguan') === '2 Minggu Sekali', 'label frekuensi salah');

$h3 = hitungPinjaman(3000000, 45, 6, 'dua_mingguan', 'flat');
pastikan($h3['total'] === 4350000.0, 'total pinjaman Rp 3 juta harus Rp 4,35 juta');
pastikan($h3['angsuran'] === 362500.0, 'angsuran Rp 3 juta harus Rp 362.500');

$g1 = hitungPinjamanGadai(1000000, 15, 1, 'mingguan');
pastikan($g1['total'] === 1190000.0, 'gadai Rp 1 juta/1 bulan harus kembali Rp 1,19 juta');
pastikan($g1['biaya_mingguan_gadai'] === 10000, 'biaya gadai Rp 1 juta harus Rp 10 ribu/minggu');
$g2 = hitungPinjamanGadai(2000000, 15, 1, 'mingguan');
pastikan($g2['total'] === 2360000.0, 'gadai Rp 2 juta/1 bulan harus kembali Rp 2,36 juta');
$g2dua = hitungPinjamanGadai(2000000, 15, 2, 'dua_mingguan');
pastikan($g2dua['tambahan_gadai'] === 120000, 'gadai Rp 2 juta/2 bulan harus mendapat tambahan Rp 120 ribu');
pastikan($g2dua['total'] === 2420000.0, 'gadai Rp 2 juta/2 bulan harus kembali Rp 2,42 juta');
pastikan($g2dua['jumlah_cicilan'] === 4, 'gadai 2 bulan/2 mingguan harus 4 cicilan');
pastikan($g2dua['angsuran'] === 605000.0, 'gadai Rp 2 juta/2 bulan harus Rp 605 ribu per cicilan');
$g3 = hitungPinjamanGadai(3000000, 15, 1, 'mingguan');
pastikan($g3['total'] === 3530000.0, 'gadai Rp 3 juta/1 bulan harus kembali Rp 3,53 juta');
$g4 = hitungPinjamanGadai(4000000, 15, 1, 'mingguan');
pastikan($g4['biaya_mingguan_gadai'] === 25000, 'pola biaya gadai Rp 4 juta harus Rp 25 ribu/minggu');
$g6dua = hitungPinjamanGadai(2000000, 15, 6, 'dua_mingguan');
pastikan($g6dua['jumlah_cicilan'] === 12, 'gadai 6 bulan/2 mingguan harus 12 cicilan');
pastikan($g6dua['total'] === 2660000.0, 'gadai Rp 2 juta/6 bulan harus kembali Rp 2,66 juta');
$g6bulan = hitungPinjamanGadai(2000000, 15, 6, 'bulanan');
pastikan($g6bulan['jumlah_cicilan'] === 6, 'gadai 6 bulan/bulanan harus 6 cicilan');
pastikan($g6bulan['total'] === $g6dua['total'], 'total gadai tidak boleh berubah karena pilihan frekuensi bayar');

echo "OK: tabel 45% tanpa jaminan dan rumus gadai semua frekuensi valid.\n";
