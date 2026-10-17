<?php
/**
 * Slip / bukti pencairan pinjaman (A5 portrait, siap cetak).
 * Dipakai oleh admin/cetak_slip_pencairan.php dan teller/cetak_slip_pencairan.php
 * setelah cek hak akses. Butuh $db dan $label_petugas.
 *
 * Total & angsuran dihitung dengan hitungDariPinjaman() (sama dengan Revisi 2 & 3).
 */

$pinjaman_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$stmt = $db->prepare("SELECT p.*, pp.kode_pengajuan, pp.tujuan_pinjaman,
                             a.nama_lengkap,
                             CASE WHEN jp.memerlukan_jaminan = 1 THEN COALESCE(a.kode_anggota_jaminan, a.kode_anggota) ELSE a.kode_anggota END AS kode_anggota,
                             jp.nama_jenis
                      FROM pinjaman p
                      JOIN pengajuan_pinjaman pp ON p.pengajuan_id = pp.id
                      JOIN anggota a ON p.anggota_id = a.id
                      JOIN jenis_pinjaman jp ON p.jenis_pinjaman_id = jp.id
                      WHERE p.id = ?");
$stmt->execute([$pinjaman_id]);
$slip = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$slip) {
    http_response_code(404);
    die('Data pinjaman tidak ditemukan.');
}

$koperasi = $db->query("SELECT * FROM koperasi WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
if (!$koperasi) {
    $koperasi = ['nama' => 'Koperasi Simpan Pinjam Sejahtera', 'alamat' => '-', 'telepon' => '-', 'email' => '-'];
}

$hitung = hitungDariPinjaman($slip);
$e = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bukti Pencairan <?php echo $e($slip['kode_pinjaman']); ?></title>
    <style>
        @page { size: A5 portrait; margin: 10mm; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #e9ecef; font-family: Arial, Helvetica, sans-serif; color: #000; font-size: 11pt; }
        .toolbar { text-align: center; padding: 12px; }
        .toolbar button { font-size: 14px; padding: 8px 18px; margin: 0 4px; cursor: pointer; }
        .slip { width: 148mm; min-height: 210mm; margin: 0 auto 24px; padding: 10mm; background: #fff;
                border: 1px solid #000; box-shadow: 0 2px 8px rgba(0,0,0,.15); }
        .kop { text-align: center; border-bottom: 3px double #000; padding-bottom: 6px; margin-bottom: 14px; }
        .kop h1 { font-size: 15pt; margin: 0 0 4px; letter-spacing: 1px; }
        .kop h2 { font-size: 12.5pt; margin: 0 0 3px; }
        .kop p { font-size: 8.5pt; margin: 0; }
        table.data { width: 100%; border-collapse: collapse; }
        table.data td { padding: 4px 2px; vertical-align: top; }
        table.data td.label { width: 40%; }
        table.data td.sep { width: 3%; }
        table.data tr.sub td { padding-top: 0; font-size: 10pt; }
        table.data tr.total td { border-top: 1px solid #000; font-weight: bold; padding-top: 6px; }
        .section { margin-top: 10px; }
        .ttd-section { margin-top: 28px; display: flex; justify-content: space-between; }
        .ttd-box { text-align: center; width: 45%; }
        .ttd-line { border-top: 1px solid #000; margin-top: 55px; padding-top: 4px; }
        .catatan { margin-top: 18px; text-align: center; font-size: 9pt; font-style: italic; }
        @media print {
            body { background: #fff; }
            .toolbar { display: none; }
            .slip { width: auto; min-height: 0; margin: 0; padding: 0; border: none; box-shadow: none; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <button type="button" onclick="window.print()">&#128424; Cetak</button>
        <button type="button" onclick="window.close()">Tutup</button>
    </div>

    <div class="slip">
        <div class="kop">
            <h1>BUKTI PENCAIRAN PINJAMAN</h1>
            <h2><?php echo $e($koperasi['nama']); ?></h2>
            <p><?php echo $e($koperasi['alamat']); ?>, Telp: <?php echo $e($koperasi['telepon']); ?>, Email: <?php echo $e($koperasi['email']); ?></p>
        </div>

        <table class="data">
            <tr><td class="label">Kode Pinjaman</td><td class="sep">:</td><td><?php echo $e($slip['kode_pinjaman']); ?></td></tr>
            <tr><td class="label">Kode Pengajuan</td><td class="sep">:</td><td><?php echo $e($slip['kode_pengajuan']); ?></td></tr>
            <tr><td class="label">Nama Anggota</td><td class="sep">:</td><td><?php echo $e($slip['nama_lengkap']); ?></td></tr>
            <tr><td class="label">Kode Anggota</td><td class="sep">:</td><td><?php echo $e($slip['kode_anggota']); ?></td></tr>
            <tr><td class="label">Tanggal Pencairan</td><td class="sep">:</td><td><?php echo $e(formatDateIndonesian($slip['tanggal_pencairan'])); ?></td></tr>
            <tr><td class="label">Jenis Pinjaman</td><td class="sep">:</td><td><?php echo $e($slip['nama_jenis']); ?></td></tr>
        </table>

        <table class="data section">
            <tr><td class="label">Tenor</td><td class="sep">:</td><td><?php echo (int) $slip['tenor']; ?> bulan</td></tr>
            <tr class="sub"><td class="label"></td><td class="sep"></td><td>Angsuran: <?php echo $e(ringkasanAngsuran($hitung)); ?></td></tr>
            <tr><td class="label">Bunga</td><td class="sep">:</td><td><?php echo $e(labelBungaRow($slip)); ?></td></tr>
            <tr><td class="label">Jumlah Pinjaman</td><td class="sep">:</td><td><?php echo formatRupiah($hitung['pokok']); ?></td></tr>
            <tr><td class="label">Angsuran per <?php echo labelPeriode($hitung['frekuensi']); ?></td><td class="sep">:</td><td><?php echo formatRupiah(round($hitung['angsuran'])); ?></td></tr>
            <?php $terakhir = end($hitung['rincian']); if (round($terakhir['jumlah']) != round($hitung['angsuran'])): ?>
            <tr class="sub"><td class="label"></td><td class="sep"></td><td>Cicilan terakhir: <?php echo formatRupiah(round($terakhir['jumlah'])); ?></td></tr>
            <?php endif; ?>
            <tr class="total"><td class="label">Total Pinjaman</td><td class="sep">:</td><td><?php echo formatRupiah(round($hitung['total'])); ?></td></tr>
            <tr><td class="label">Tujuan Pinjaman</td><td class="sep">:</td><td><?php echo $e($slip['tujuan_pinjaman'] ?: '-'); ?></td></tr>
        </table>

        <div class="ttd-section">
            <div class="ttd-box">
                <div class="ttd-line"><strong>PENERIMA</strong><br><?php echo $e($slip['nama_lengkap']); ?></div>
            </div>
            <div class="ttd-box">
                <div class="ttd-line"><strong><?php echo $e($label_petugas); ?></strong><br><?php echo $e($_SESSION['nama_lengkap']); ?></div>
            </div>
        </div>

        <div class="catatan">Slip ini sebagai bukti sah pencairan pinjaman</div>
    </div>
</body>
</html>
