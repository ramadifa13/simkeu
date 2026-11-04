@php
    use App\Support\DocumentText as T;
    $frekuensi = $loan->frekuensi ?? 'bulanan';
    $tampilkanMinggu = ! empty($loan->memerlukan_jaminan) && ($frekuensi === 'mingguan' || ($frekuensi === 'bulanan' && ! empty($tampilkanMingguBulanan)));
    $biayaMingguan = $tampilkanMinggu ? (T::weeklyPawnFee((float) $loan->jumlah_pinjaman) ?? 0) : 0;
    $jumlahMingguPerAngsuran = $frekuensi === 'bulanan' ? 4 : 1;
    $label = ['mingguan' => 'Mingguan', 'dua_mingguan' => 'Dua Mingguan', 'bulanan' => 'Bulanan'][$frekuensi] ?? 'Bulanan';
    $hari = [1 => 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'];
    $hariJadwal = [];
    foreach ($installments as $row) {
        $hariJadwal[$hari[(int) date('N', strtotime($row->tanggal_jatuh_tempo))]] = true;
    }
    $saldo = (float) $loan->jumlah_pinjaman;
    $totalPokok = $totalMargin = $totalMinggu = $totalAngsuran = 0;
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Buku Angsuran {{ $loan->kode_pinjaman }}</title>
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
<div class="kop"><strong>{{ $cooperative->nama ?? config('app.name') }}</strong><p>{{ $cooperative->alamat ?? '' }}</p></div>
<h1>Jadwal Angsuran {{ $label }} ({{ T::rupiah($loan->jumlah_pinjaman) }})</h1>
<table class="info">
<tr><td>Nama</td><td>: {{ $loan->nama_lengkap }} ({{ $loan->kode_anggota }})</td></tr>
<tr><td>Alamat</td><td>: {{ $loan->alamat }}</td></tr>
<tr><td>Kode Pinjaman</td><td>: {{ $loan->kode_pinjaman }}</td></tr>
<tr><td>Modal Kerja</td><td>: {{ T::rupiah($loan->jumlah_pinjaman) }}</td></tr>
<tr><td>Jangka Waktu</td><td>: {{ (int) $loan->tenor }} bulan / {{ $installments->count() }} kali angsuran {{ strtolower($label) }}</td></tr>
<tr><td>Margin</td><td>: {{ $loan->bunga }}% ({{ $loan->skema_bunga ?? 'flat' }})</td></tr>
<tr><td>Tanggal Pencairan</td><td>: {{ ! empty($loan->tanggal_pencairan) ? date('d/m/Y', strtotime($loan->tanggal_pencairan)) : '-' }}</td></tr>
<tr><td>Hari Angsuran</td><td>: {{ implode(', ', array_keys($hariJadwal)) }}</td></tr>
</table>
<table class="jadwal">
<thead><tr><th rowspan="2">Ke</th><th rowspan="2">Tanggal</th><th rowspan="2">K</th>@if($tampilkanMinggu)<th rowspan="2">Minggu</th>@endif<th colspan="3">Angsuran {{ $label }}</th><th colspan="2">Saldo</th></tr><tr><th>Modal Kerja</th><th>Margin Dasar</th><th>Jumlah</th><th>Modal Kerja</th><th>Ttd</th></tr></thead>
<tbody>
<tr><td colspan="{{ $tampilkanMinggu ? 7 : 6 }}">Saldo awal</td><td class="angka">{{ T::rupiah($saldo) }}</td><td></td></tr>
@foreach ($installments as $row)
    @php
        $saldo -= (float) $row->pokok;
        $totalPokok += (float) $row->pokok;
        $marginMinggu = ($biayaMingguan ?: 0) * $jumlahMingguPerAngsuran;
        $marginDasar = max(0, (float) $row->bunga - $marginMinggu);
        $totalMargin += $marginDasar;
        $totalMinggu += $marginMinggu;
        $totalAngsuran += (float) $row->jumlah_angsuran;
    @endphp
<tr>
    <td class="tengah">{{ (int) $row->angsuran_ke }}</td>
    <td class="tengah">{{ date('d/m/Y', strtotime($row->tanggal_jatuh_tempo)) }}</td>
    <td></td>
    @if($tampilkanMinggu)<td class="angka">{{ T::rupiah($marginMinggu) }}</td>@endif
    <td class="angka">{{ T::rupiah($row->pokok) }}</td>
    <td class="angka">{{ T::rupiah($marginDasar) }}</td>
    <td class="angka">{{ T::rupiah($row->jumlah_angsuran) }}</td>
    <td class="angka">{{ T::rupiah(abs($saldo) < 0.005 ? 0 : $saldo) }}</td>
    <td style="min-width: 12mm"></td>
</tr>
@endforeach
<tr><th colspan="3">Jumlah</th>@if($tampilkanMinggu)<th class="angka">{{ T::rupiah($totalMinggu) }}</th>@endif<th class="angka">{{ T::rupiah($totalPokok) }}</th><th class="angka">{{ T::rupiah($totalMargin) }}</th><th class="angka">{{ T::rupiah($totalAngsuran) }}</th><td colspan="2"></td></tr>
</tbody></table>
<div class="catatan">K = Kode / keterangan. Kolom K dan tanda tangan diisi saat pembayaran.<br>Tanggal libur: ____________________<br>Jadwal ini mengikuti data pinjaman. Denda keterlambatan mengikuti ketentuan koperasi.</div>
</main>
</body>
</html>
