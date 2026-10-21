<?php
// Jadwal kontraktual dari database; tidak menghitung ulang dengan tarif terbaru.
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id || $id < 1) {
    http_response_code(400);
    exit('ID pinjaman tidak valid.');
}
$stmt = $db->prepare('SELECT p.*, jp.memerlukan_jaminan, a.nama_lengkap, a.kode_anggota, a.alamat
    FROM pinjaman p JOIN anggota a ON a.id = p.anggota_id JOIN jenis_pinjaman jp ON jp.id=p.jenis_pinjaman_id WHERE p.id = ?');
$stmt->execute([$id]);
$p = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$p) {
    http_response_code(404);
    exit('Pinjaman tidak ditemukan.');
}
$stmt = $db->prepare('SELECT * FROM angsuran WHERE pinjaman_id = ? ORDER BY angsuran_ke, id');
$stmt->execute([$id]);
$jadwal = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (!$jadwal) {
    http_response_code(404);
    exit('Jadwal angsuran belum tersedia.');
}
$koperasi = $db->query('SELECT * FROM koperasi WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
$e = function ($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); };
$uang = function ($value) { return 'Rp ' . number_format($value, 0, ',', '.'); };
$frekuensi = $p['frekuensi'] ?? 'bulanan';
$tampilkanMinggu = !empty($p['memerlukan_jaminan'])
    && ($frekuensi === 'mingguan' || ($frekuensi === 'bulanan' && !empty($tampilkanMingguBulanan)));
$biayaMingguan = $tampilkanMinggu && function_exists('biayaMingguanGadai') ? biayaMingguanGadai($p['jumlah_pinjaman']) : 0;
$jumlahMingguPerAngsuran = $frekuensi === 'bulanan' ? 4 : 1;
$label = ['mingguan' => 'Mingguan', 'dua_mingguan' => 'Dua Mingguan', 'bulanan' => 'Bulanan'][$frekuensi] ?? 'Bulanan';
$hari = [1 => 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'];
$hari_jadwal = [];
foreach ($jadwal as $row) {
    $hari_jadwal[$hari[(int) date('N', strtotime($row['tanggal_jatuh_tempo']))]] = true;
}
$saldo = (float) $p['jumlah_pinjaman'];
$total_pokok = $total_margin = $total_minggu = $total_angsuran = 0;
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Buku Angsuran <?= $e($p['kode_pinjaman']) ?></title>
<style>
@page { size: A4 portrait; margin: 10mm; }
* { box-sizing: border-box; }
body { font: 11pt Arial, sans-serif; color: #000; background: #eee; margin: 0; }
.toolbar { padding: 16px; text-align: center; }
button { padding: 8px 18px; cursor: pointer; }
main { background: white; width: 190mm; max-width: 100%; margin: 0 auto 20px; padding: 7mm 7mm 6mm; }
h1 { font-size: 16pt; text-align: center; margin: 8px 0 12px; }
.kop { text-align: center; border-bottom: 3px double; padding-bottom: 8px; }
.kop p { margin: 4px; }
.info { width: 100%; margin: 14px 0; }
.info td { padding: 4px 0; vertical-align: top; overflow-wrap: anywhere; }
.info td:first-child { width: 40mm; }
.jadwal { width: 100%; border-collapse: collapse; table-layout: fixed; font-size: 9pt; }
.jadwal th, .jadwal td { border: 1px solid #000; padding: 6px 3px; vertical-align: middle; }
.angka { text-align: right; white-space: nowrap; }
.tengah { text-align: center; }
.catatan { font-size: 9pt; margin-top: 12px; break-inside: avoid; }
thead { display: table-header-group; }
tr { break-inside: avoid; }
@media print { body { background: white; } .toolbar { display: none; } main { width: 100%; margin: 0; padding: 0; } }
</style>
</head>
<body>
<div class="toolbar"><button onclick="window.print()">Cetak / Simpan PDF</button> <button onclick="window.close()">Tutup</button></div>
<main>
<div class="kop"><strong><?= $e($koperasi['nama'] ?? APP_NAME) ?></strong><p><?= $e($koperasi['alamat'] ?? '') ?></p></div>
<h1>Jadwal Angsuran <?= $e($label) ?> (<?= $uang($p['jumlah_pinjaman']) ?>)</h1>
<table class="info">
<tr><td>Nama</td><td>: <?= $e($p['nama_lengkap']) ?> (<?= $e($p['kode_anggota']) ?>)</td></tr>
<tr><td>Alamat</td><td>: <?= $e($p['alamat']) ?></td></tr>
<tr><td>Kode Pinjaman</td><td>: <?= $e($p['kode_pinjaman']) ?></td></tr>
<tr><td>Modal Kerja</td><td>: <?= $uang($p['jumlah_pinjaman']) ?></td></tr>
<tr><td>Jangka Waktu</td><td>: <?= (int) $p['tenor'] ?> bulan / <?= count($jadwal) ?> kali angsuran <?= $e(strtolower($label)) ?></td></tr>
<tr><td>Margin</td><td>: <?= $e($p['bunga']) ?>% (<?= $e($p['skema_bunga'] ?? 'flat') ?>)</td></tr>
<tr><td>Tanggal Pencairan</td><td>: <?= !empty($p['tanggal_pencairan']) ? date('d/m/Y', strtotime($p['tanggal_pencairan'])) : '-' ?></td></tr>
<tr><td>Hari Angsuran</td><td>: <?= $e(implode(', ', array_keys($hari_jadwal))) ?></td></tr>
</table>
<table class="jadwal">
<colgroup><?php if($tampilkanMinggu): ?><col style="width: 5%"><col style="width: 13%"><col style="width: 4%"><col style="width: 10%"><col style="width: 14%"><col style="width: 12%"><col style="width: 14%"><col style="width: 16%"><col style="width: 12%"><?php else: ?><col style="width: 6%"><col style="width: 14%"><col style="width: 4%"><col style="width: 16%"><col style="width: 14%"><col style="width: 16%"><col style="width: 18%"><col style="width: 12%"><?php endif; ?></colgroup>
<thead><tr><th rowspan="2">Ke</th><th rowspan="2">Tanggal</th><th rowspan="2">K</th><?php if($tampilkanMinggu): ?><th rowspan="2">Minggu</th><?php endif; ?><th colspan="3">Angsuran <?= $e($label) ?></th><th colspan="2">Saldo</th></tr><tr><th>Modal Kerja</th><th>Margin Dasar</th><th>Jumlah</th><th>Modal Kerja</th><th>Ttd</th></tr></thead>
<tbody>
<tr><td colspan="<?= $tampilkanMinggu ? 7 : 6 ?>">Saldo awal</td><td class="angka"><?= $uang($saldo) ?></td><td></td></tr>
<?php foreach ($jadwal as $row):
    $saldo -= (float) $row['pokok'];
    $total_pokok += (float) $row['pokok'];
    $marginMinggu = ($biayaMingguan ?: 0) * $jumlahMingguPerAngsuran;
    $marginDasar = max(0, (float) $row['bunga'] - $marginMinggu);
    $total_margin += $marginDasar;
    $total_minggu += $marginMinggu;
    $total_angsuran += (float) $row['jumlah_angsuran'];
?>
<tr><td class="tengah"><?= (int) $row['angsuran_ke'] ?></td><td class="tengah"><?= date('d/m/Y', strtotime($row['tanggal_jatuh_tempo'])) ?></td><td></td><?php if($tampilkanMinggu): ?><td class="angka"><?= $uang($marginMinggu) ?></td><?php endif; ?><td class="angka"><?= $uang($row['pokok']) ?></td><td class="angka"><?= $uang($marginDasar) ?></td><td class="angka"><?= $uang($row['jumlah_angsuran']) ?></td><td class="angka"><?= $uang(abs($saldo) < 0.005 ? 0 : $saldo) ?></td><td style="min-width: 12mm"></td></tr>
<?php endforeach; ?>
<tr><th colspan="3">Jumlah</th><?php if($tampilkanMinggu): ?><th class="angka"><?= $uang($total_minggu) ?></th><?php endif; ?><th class="angka"><?= $uang($total_pokok) ?></th><th class="angka"><?= $uang($total_margin) ?></th><th class="angka"><?= $uang($total_angsuran) ?></th><td colspan="2"></td></tr>
</tbody></table>
<div class="catatan">K = Kode / keterangan. Kolom K dan tanda tangan diisi saat pembayaran.<br>Tanggal libur: ____________________<br>Jadwal ini mengikuti data pinjaman. Denda keterlambatan mengikuti ketentuan koperasi.</div>
</main>
</body>
</html>
