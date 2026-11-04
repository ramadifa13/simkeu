@php
    use App\Support\DocumentText as T;
    $bulanRomawi = [1=>'I','II','III','IV','V','VI','VII','VIII','IX','X','XI','XII'];
    $nomor = str_pad((string) $application->id, 3, '0', STR_PAD_LEFT).'/SPHP/'.$bulanRomawi[(int) date('n')].'/'.date('Y');
    $lahir = trim(($application->tempat_lahir ?? '').(! empty($application->tanggal_lahir) ? ', '.date('d/m/Y', strtotime($application->tanggal_lahir)) : ''));
    $kode = ! empty($application->memerlukan_jaminan)
        ? ($application->kode_anggota_jaminan ?: $application->kode_anggota)
        : $application->kode_anggota;
@endphp
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>SPHP {{ $application->nama_lengkap }}</title>
<style>
@page{size:A4;margin:17mm}*{box-sizing:border-box}body{font-family:"Times New Roman",serif;color:#000;margin:0;background:#eee;font-size:12pt;line-height:1.35}.bar{position:sticky;top:0;text-align:center;padding:10px;background:#fff;border-bottom:1px solid #ccc}.bar button{padding:9px 18px}.page{width:210mm;min-height:297mm;margin:15px auto;background:#fff;padding:17mm}.center{text-align:center}.title{font-size:16pt;font-weight:bold;text-decoration:underline;margin:0}.sub{margin:2px 0 20px}.party{margin:8px 0 14px}.party table{width:100%;border-collapse:collapse}.party td:first-child{width:34mm}.article{font-weight:bold;margin:14px 0 4px}.place{text-align:right;margin:16px 0 0}.sign{width:100%;margin-top:18px;text-align:center}.sign td{width:50%;vertical-align:top;height:120px}.sign-name{margin-top:70px}@media print{body{background:#fff}.bar{display:none}.page{width:auto;min-height:0;margin:0;padding:0}}
</style>
</head>
<body>
<div class="bar"><button onclick="window.print()">Cetak / Simpan PDF</button></div>
<main class="page">
<div class="center"><h1 class="title">SURAT PERJANJIAN HUTANG PIUTANG</h1><p class="sub">Nomor: {{ $nomor }}</p></div>
<p>Yang bertanda tangan di bawah ini:</p>
<div class="party"><strong>PIHAK PERTAMA - PIHAK YANG MEMBERI PINJAMAN</strong>
<table>
<tr><td>Nama</td><td>: {{ ($settings->nama_pihak_pertama ?? null) ?: ($cooperative->nama ?? '') }}</td></tr>
<tr><td>NIK</td><td>: {{ $settings->nik_pihak_pertama ?? '' }}</td></tr>
<tr><td>Alamat</td><td>: {{ $settings->alamat_pihak_pertama ?? '' }}</td></tr>
<tr><td>No. HP</td><td>: {{ $settings->telepon_pihak_pertama ?? '' }}</td></tr>
</table></div>
<div class="party"><strong>PIHAK KEDUA - PIHAK YANG MENERIMA PINJAMAN</strong>
<table>
<tr><td>Nama</td><td>: {{ $application->nama_lengkap }}</td></tr>
<tr><td>Kode</td><td>: {{ $kode }}</td></tr>
<tr><td>NIK</td><td>: {{ $application->ktp }}</td></tr>
<tr><td>Tempat/Tgl Lahir</td><td>: {{ $lahir }}</td></tr>
<tr><td>Alamat</td><td>: {{ $application->alamat }}</td></tr>
<tr><td>No. HP</td><td>: {{ $application->telepon }}</td></tr>
</table></div>
<p>Para pihak dengan ini sepakat mengikatkan diri dalam Perjanjian Hutang Piutang dengan syarat-syarat sebagai berikut:</p>
<div class="article">PASAL 1: JUMLAH PINJAMAN</div>
<div>1. PIHAK PERTAMA setuju memberikan pinjaman kepada PIHAK KEDUA sebesar {{ T::rupiah($application->jumlah_pinjaman) }} ({{ T::terbilangRupiah($application->jumlah_pinjaman) }}).</div>
<div>2. PIHAK KEDUA menerima pinjaman tersebut penuh tanpa potongan atau 100%.</div>
<div class="article">PASAL 2: TUJUAN PINJAMAN</div>
<div>Pinjaman tersebut digunakan oleh PIHAK KEDUA untuk keperluan: {{ $application->tujuan_pinjaman ?: '-' }}</div>
<div class="article">PASAL 3: BUNGA DAN JANGKA WAKTU</div>
<div>1. Jangka waktu pinjaman: {{ (int) $application->tenor }} bulan</div>
<div>2. Frekuensi angsuran: {{ T::frequency($application->frekuensi ?? 'bulanan') }}</div>
<div>3. Suku bunga: {{ T::interestLabel($application->bunga, $application->skema_bunga ?? 'flat') }}</div>
@if(!empty($application->memerlukan_jaminan))
<div class="article">PASAL JAMINAN</div>
<div>Jenis: {{ $application->jenis_barang ?: '-' }}<br>{{ trim(($application->identitas_barang ?: '')."\n".($application->deskripsi_barang ?: '')) }}</div>
@endif
<p class="place">Dibuat di Mojokerto<br>Pada tanggal: {{ date('d/m/Y') }}</p>
<table class="sign"><tr>
<td>PIHAK PERTAMA<div class="sign-name">({{ ($settings->nama_pihak_pertama ?? null) ?: ($cooperative->nama ?? '...............................') }})</div></td>
<td>PIHAK KEDUA<div class="sign-name">({{ $application->nama_lengkap }})</div></td>
</tr></table>
</main>
</body>
</html>
