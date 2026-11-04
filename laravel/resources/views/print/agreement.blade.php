@php
    use App\Support\DocumentText as T;
    $pp = [
        'nama' => trim((string) ($settings->nama_pihak_pertama ?? '')) ?: ($cooperative->nama ?? 'Koperasi'),
        'nik' => trim((string) ($settings->nik_pihak_pertama ?? '')),
        'alamat' => trim((string) ($settings->alamat_pihak_pertama ?? '')),
        'telepon' => trim((string) ($settings->telepon_pihak_pertama ?? '')),
    ];
    $lahir = trim(($loan->tempat_lahir ?? '').(! empty($loan->tanggal_lahir) ? ', '.date('d/m/Y', strtotime($loan->tanggal_lahir)) : ''));
    $jaminan = is_array($snapshot['jaminan'] ?? null) ? $snapshot['jaminan'] : [];
    $isi = (string) ($snapshot['isi_perjanjian'] ?? '');
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Surat Perjanjian {{ $agreement->nomor_perjanjian }}</title>
<style>
@page{size:A4;margin:16mm}
*{box-sizing:border-box}
body{margin:0;background:#eee;font-family:DejaVu Sans,Arial,sans-serif;color:#000;font-size:10.5pt;line-height:1.4}
.toolbar{padding:12px;text-align:center}
button{padding:8px 18px;cursor:pointer}
main{width:210mm;max-width:100%;margin:0 auto 20px;padding:16mm;background:#fff}
.center{text-align:center}
h1{font-size:15pt;margin:0}
hr{border:0;border-top:1px solid #000;margin:10px 0}
h4{margin:14px 0 6px;font-size:11pt}
table.info{width:100%;border-collapse:collapse}
table.info td{padding:2px 0;vertical-align:top}
table.info td:first-child{width:48mm}
.schedule{width:100%;border-collapse:collapse;font-size:9pt;margin-top:10px}
.schedule th,.schedule td{border:1px solid #000;padding:4px;text-align:center}
.sign{width:100%;margin-top:22px;text-align:center}
.sign td{width:33%;vertical-align:top;padding:0 6px}
.line{border-top:1px solid #000;margin-top:55px;padding-top:4px}
@media print{body{background:#fff}.toolbar{display:none}main{width:auto;margin:0;padding:0}}
</style>
</head>
<body>
<div class="toolbar"><button onclick="window.print()">Cetak / Simpan PDF</button> <button onclick="window.close()">Tutup</button></div>
<main>
<div class="center"><b style="font-size:15px">SURAT PERJANJIAN PINJAMAN</b><br>{{ $cooperative->nama ?? 'Koperasi' }}<br><small>No. {{ $agreement->nomor_perjanjian }} · Versi {{ ucfirst($agreement->versi ?? '-') }}</small></div>
<hr>
<h4>PIHAK PERTAMA — PEMBERI PINJAMAN</h4>
<table class="info">
<tr><td>Nama</td><td>: {{ $pp['nama'] }}</td></tr>
<tr><td>NIK</td><td>: {{ $pp['nik'] }}</td></tr>
<tr><td>Alamat</td><td>: {{ $pp['alamat'] }}</td></tr>
<tr><td>No. HP</td><td>: {{ $pp['telepon'] }}</td></tr>
</table>
<h4>PIHAK KEDUA — PENERIMA PINJAMAN</h4>
<table class="info">
<tr><td>Nama</td><td>: <b>{{ $loan->nama_lengkap }}</b></td></tr>
<tr><td>Kode</td><td>: {{ $loan->kode_anggota_tampil ?? $loan->kode_anggota }}</td></tr>
<tr><td>NIK</td><td>: {{ $loan->ktp }}</td></tr>
<tr><td>Tempat, Tgl Lahir</td><td>: {{ $lahir }}</td></tr>
<tr><td>Alamat</td><td>: {{ $loan->alamat }}</td></tr>
<tr><td>No. HP</td><td>: {{ $loan->telepon }}</td></tr>
</table>
<h4>RINCIAN PINJAMAN</h4>
<table class="info">
<tr><td>Jenis Pinjaman</td><td>: {{ $loan->nama_jenis }}</td></tr>
<tr><td>Nilai Pinjaman</td><td>: <b>{{ T::rupiah($loan->jumlah_pinjaman) }}</b></td></tr>
<tr><td>Tujuan Pinjaman</td><td>: {{ $loan->tujuan_pinjaman ?: '-' }}</td></tr>
<tr><td>Tenor</td><td>: {{ $loan->tenor }} bulan</td></tr>
<tr><td>Frekuensi</td><td>: {{ $loan->frekuensi }} ({{ $loan->jumlah_cicilan }} kali)</td></tr>
<tr><td>Bunga</td><td>: {{ $loan->bunga }}%</td></tr>
<tr><td>Total</td><td>: {{ T::rupiah($loan->total_pinjaman) }}</td></tr>
<tr><td>Angsuran</td><td>: {{ T::rupiah($loan->angsuran_per_bulan) }}</td></tr>
<tr><td>Pencairan</td><td>: {{ !empty($loan->tanggal_pencairan) ? date('d/m/Y', strtotime($loan->tanggal_pencairan)) : '-' }}</td></tr>
<tr><td>Jatuh Tempo</td><td>: <b>{{ !empty($loan->jatuh_tempo) ? date('d/m/Y', strtotime($loan->jatuh_tempo)) : '-' }}</b></td></tr>
</table>
@if(!empty($jaminan['ada']))
    <h4>PASAL 3 - JAMINAN</h4>
    <p>Sebagai jaminan atas hutang tersebut, PIHAK KEDUA menyerahkan jaminan berupa:<br><b>Jenis:</b> {{ $jaminan['jenis'] ?? '-' }}<br>{!! nl2br(e($jaminan['detail'] ?? '')) !!}<br>Jaminan dikembalikan sepenuhnya setelah seluruh hutang dan bunga lunas 100%.</p>
@elseif($isi !== '')
    <h4>Ketentuan Perjanjian</h4>
    <div>{!! nl2br(e($isi)) !!}</div>
@endif
<p>Dengan membubuhkan tanda tangan di bawah ini, para pihak menyatakan data telah dibaca dan disetujui.</p>
<p style="text-align:right">Dibuat di {{ $cooperative->alamat ?: 'Mojokerto' }}<br>Pada tanggal: {{ !empty($loan->tanggal_pencairan) ? date('d/m/Y', strtotime($loan->tanggal_pencairan)) : date('d/m/Y') }}</p>
<table class="sign"><tr>
<td><div class="line"><strong>Nasabah</strong><br>{{ $loan->nama_lengkap }}</div></td>
<td><div class="line"><strong>Teller</strong><br>&nbsp;</div></td>
<td><div class="line"><strong>{{ $settings->jabatan_admin ?? 'Pengurus' }}</strong><br>{{ $pp['nama'] }}</div></td>
</tr></table>
@if($installments->isNotEmpty())
<section style="margin-top:28px">
<h4 class="center">LAMPIRAN: TABEL ANGSURAN</h4>
<table class="schedule">
<thead><tr><th>Ke</th><th>Jatuh Tempo</th><th>Pokok</th><th>Bunga</th><th>Total</th></tr></thead>
<tbody>
@foreach($installments as $row)
<tr>
<td>{{ $row->angsuran_ke }}</td>
<td>{{ date('d/m/Y', strtotime($row->tanggal_jatuh_tempo)) }}</td>
<td>{{ T::rupiah($row->pokok) }}</td>
<td>{{ T::rupiah($row->bunga) }}</td>
<td>{{ T::rupiah($row->jumlah_angsuran) }}</td>
</tr>
@endforeach
</tbody>
</table>
</section>
@endif
</main>
</body>
</html>
