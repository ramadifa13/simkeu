@php
    use App\Support\DocumentText as T;
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bukti Pelunasan {{ $loan->kode_pinjaman }}</title>
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
        table.data tr.total td { border-top: 1px solid #000; font-weight: bold; padding-top: 6px; }
        .section { margin-top: 10px; }
        .lunas { margin: 16px 0 4px; text-align: center; border: 2px solid #1a7f37; color: #1a7f37;
                 padding: 8px; font-weight: bold; font-size: 11.5pt; letter-spacing: .5px; }
        .ttd-section { margin-top: 28px; display: flex; justify-content: space-between; }
        .ttd-box { text-align: center; width: 45%; }
        .ttd-line { border-top: 1px solid #000; margin-top: 55px; padding-top: 4px; }
        @media print {
            body { background: #fff; }
            .toolbar { display: none; }
            .slip { width: auto; min-height: 0; margin: 0; padding: 0; border: none; box-shadow: none; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <button type="button" onclick="window.print()">Cetak</button>
        <button type="button" onclick="window.close()">Tutup</button>
    </div>

    <div class="slip">
        <div class="kop">
            <h1>BUKTI PELUNASAN PINJAMAN</h1>
            <h2>{{ $cooperative->nama ?? 'Koperasi' }}</h2>
            <p>{{ $cooperative->alamat ?? '-' }}, Telp: {{ $cooperative->telepon ?? '-' }}, Email: {{ $cooperative->email ?? '-' }}</p>
        </div>

        <table class="data">
            <tr><td class="label">Kode Pinjaman</td><td class="sep">:</td><td>{{ $loan->kode_pinjaman }}</td></tr>
            <tr><td class="label">Kode Anggota</td><td class="sep">:</td><td>{{ $loan->kode_anggota }}</td></tr>
            <tr><td class="label">Nama Anggota</td><td class="sep">:</td><td>{{ $loan->nama_lengkap }}</td></tr>
            <tr><td class="label">Jenis Pinjaman</td><td class="sep">:</td><td>{{ $loan->nama_jenis }}</td></tr>
        </table>

        <table class="data section">
            <tr><td class="label">Jumlah Pinjaman</td><td class="sep">:</td><td>{{ T::rupiah($loan->jumlah_pinjaman) }}</td></tr>
            <tr><td class="label">Tenor</td><td class="sep">:</td><td>{{ (int) $loan->tenor }} bulan &mdash; {{ T::frequency($loan->frekuensi ?? 'bulanan') }} {{ (int) ($loan->jumlah_cicilan ?? $totalCicilan) }}&times;</td></tr>
            <tr><td class="label">Total Angsuran Dibayar</td><td class="sep">:</td><td>{{ T::rupiah($totalAngsuranDibayar) }}</td></tr>
            <tr><td class="label">Total Denda</td><td class="sep">:</td><td>{{ $totalDenda > 0 ? T::rupiah($totalDenda) : 'Tidak ada' }}</td></tr>
            <tr class="total"><td class="label">Total Dibayar</td><td class="sep">:</td><td>{{ T::rupiah($totalDibayar) }}</td></tr>
        </table>

        <table class="data section">
            <tr><td class="label">Tanggal Pencairan</td><td class="sep">:</td><td>{{ T::dateId($loan->tanggal_pencairan) }}</td></tr>
            <tr><td class="label">Tanggal Lunas</td><td class="sep">:</td><td>{{ $tanggalLunas ? T::dateId($tanggalLunas) : '-' }}</td></tr>
        </table>

        <div class="lunas">Pinjaman telah dinyatakan LUNAS</div>
        <div class="section">
            <p><strong>Keterangan lunas</strong></p>
            <p>{!! nl2br(e($keteranganLunas)) !!}</p>
            <p>Jenis penutupan: <strong>{{ $jenisPenutupan }}</strong>.</p>
        </div>

        <div class="ttd-section">
            <div class="ttd-box">
                @if($khususAgtj)
                    <div class="ttd-line"><strong>NASABAH</strong><br>{{ $loan->nama_lengkap }}</div>
                @else
                    <div class="ttd-line"><strong>ADMIN</strong><br>{!! $namaAdmin !== '' ? e($namaAdmin) : '&nbsp;' !!}</div>
                @endif
            </div>
            <div class="ttd-box">
                <div class="ttd-line"><strong>TELLER</strong><br>{!! $namaTeller !== '' ? e($namaTeller) : '&nbsp;' !!}</div>
            </div>
        </div>
    </div>
</body>
</html>
