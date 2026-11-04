@php
    use App\Support\DocumentText as T;
    $bulanRomawi = ['01'=>'I','02'=>'II','03'=>'III','04'=>'IV','05'=>'V','06'=>'VI','07'=>'VII','08'=>'VIII','09'=>'IX','10'=>'X','11'=>'XI','12'=>'XII'];
    $ts = strtotime($bast->diserahkan_at ?: 'now');
    $nomor = 'SPHPJ-KEMBALI/'.str_pad((string) $bast->id, 4, '0', STR_PAD_LEFT).'/'.($bulanRomawi[date('m', $ts)] ?? '').'/'.date('Y', $ts);
    $tanggalTampil = T::dateId(date('Y-m-d', $ts));
    $teksBast = 'Sehubungan dengan telah LUNAS-nya pinjaman No. Kode '.$loan->kode_pinjaman.' atas nama '.$loan->nama_lengkap.', maka pada hari ini '.($cooperative->nama ?? 'Koperasi').' mengembalikan barang jaminan kepada nasabah dalam kondisi sama seperti saat diterima.';
@endphp
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>BAST {{ $nomor }}</title>
<style>
@page{size:A4 portrait;margin:13mm}*{box-sizing:border-box}body{margin:0;background:#edf1f3;color:#172c3a;font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:1.55}.toolbar{text-align:center;padding:12px}.toolbar button{padding:8px 16px;margin:0 4px;cursor:pointer}.sheet{width:210mm;min-height:297mm;margin:22px auto;padding:17mm 18mm;background:#fff;box-shadow:0 8px 32px #193b4726}.letterhead{text-align:center;padding-bottom:12px;border-bottom:3px double #233f4b}.letterhead h1{margin:0;font-size:17px;text-transform:uppercase}.letterhead p{margin:2px 0 0;color:#526b76;font-size:9px}.document-title{margin:24px 0 20px;text-align:center}.document-title h2{margin:0;font-size:15px;text-decoration:underline}.info-grid{display:grid;grid-template-columns:1fr 1fr;border:1px solid #cbd8dd;border-radius:8px;overflow:hidden}.info-item{display:grid;grid-template-columns:112px 1fr;gap:9px;padding:9px 11px;border-bottom:1px solid #dce5e9}.info-item:nth-child(odd){border-right:1px solid #dce5e9}.section{margin-top:20px}.asset-box{padding:13px 14px;border:1px solid #d3dfe4;border-radius:8px;background:#f8fafb}.asset-meta{display:grid;grid-template-columns:145px 1fr;margin:3px 0}.confirmation{margin-top:18px;padding:11px 13px;border:1px solid #b9ddcd;border-radius:8px;background:#eff9f4;color:#245f49}.ttd{display:flex;justify-content:space-between;margin-top:28px}.ttd div{width:45%;text-align:center;border-top:1px solid #71868f;padding-top:6px;margin-top:70px}@media print{body{background:#fff}.toolbar{display:none}.sheet{width:auto;margin:0;padding:0;box-shadow:none}}
</style>
</head>
<body>
<div class="toolbar"><button onclick="window.print()">Cetak / Simpan PDF</button><button onclick="window.close()">Tutup</button></div>
<main class="sheet">
<header class="letterhead"><h1>{{ $cooperative->nama ?? 'Koperasi' }}</h1><p>{{ $cooperative->alamat ?? '-' }}</p><p>Telp. {{ $cooperative->telepon ?? '-' }} · {{ $cooperative->email ?? '-' }}</p></header>
<div class="document-title"><h2>BERITA ACARA PENGEMBALIAN BARANG JAMINAN</h2><p>Nomor: {{ $nomor }}</p></div>
<p>{{ $teksBast }}</p>
<section class="info-grid">
<div class="info-item"><span>Nama nasabah</span><strong>{{ $loan->nama_lengkap }}</strong></div>
<div class="info-item"><span>Kode anggota</span><strong>{{ $loan->kode_anggota }}</strong></div>
<div class="info-item"><span>Nomor identitas</span><strong>{{ $ktp }}</strong></div>
<div class="info-item"><span>Kode pinjaman</span><strong>{{ $loan->kode_pinjaman }}</strong></div>
<div class="info-item"><span>Petugas</span><strong>{{ $bast->petugas }}</strong></div>
<div class="info-item"><span>Tanggal transaksi</span><strong>{{ $tanggalTampil }}</strong></div>
</section>
<section class="section">
<h3>Rincian pengembalian</h3>
<div class="asset-box">
<p class="asset-meta"><span>Jenis barang</span><strong>{{ $collateral->jenis_barang ?? 'Barang jaminan' }}</strong></p>
<p class="asset-meta"><span>Identitas / nomor</span><strong>{{ $collateral->identitas_barang ?? '-' }}</strong></p>
<p class="asset-meta"><span>Berat / spesifikasi</span><strong>{!! nl2br(e($collateral->deskripsi_barang ?? '-')) !!}</strong></p>
<p class="asset-meta"><span>Status pinjaman</span><strong>LUNAS</strong></p>
</div>
<div class="confirmation">Dengan ditandatanganinya surat ini, maka segala kewajiban kedua belah pihak telah selesai dan tidak ada tuntutan di kemudian hari.<br><strong>{{ $cooperative->alamat ?: 'Mojokerto' }}, {{ $tanggalTampil }}</strong></div>
</section>
<div class="ttd">
<div><strong>{{ $loan->nama_lengkap }}</strong><br><span>Nasabah / penerima jaminan</span></div>
<div><strong>{{ $bast->petugas }}</strong><br><span>Teller / petugas penyerahan</span></div>
</div>
</main>
</body>
</html>
