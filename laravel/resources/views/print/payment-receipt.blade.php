@php
    use App\Support\DocumentText as T;
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bukti Angsuran {{ $loan->kode_pinjaman }} ke-{{ (int) $payment->angsuran_ke }}</title>
    <style>
        @page { size: A5 landscape; margin: 8mm; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #e9ecef; font-family: Arial, Helvetica, sans-serif; color: #000; font-size: 10.5pt; }
        .toolbar { text-align: center; padding: 12px; }
        .toolbar button { font-size: 14px; padding: 8px 18px; margin: 0 4px; cursor: pointer; }
        .slip { width: 210mm; min-height: 130mm; margin: 0 auto 24px; padding: 8mm 10mm; background: #fff;
                border: 1px solid #000; box-shadow: 0 2px 8px rgba(0,0,0,.15); position: relative; }
        .kop { text-align: center; border-bottom: 3px double #000; padding-bottom: 5px; margin-bottom: 10px; }
        .kop h1 { font-size: 14pt; margin: 0 0 3px; letter-spacing: 1px; }
        .kop h2 { font-size: 12pt; margin: 0 0 2px; }
        .kop p { font-size: 8.5pt; margin: 0; }
        .cols { display: flex; gap: 8mm; }
        .cols > div { flex: 1; }
        table.data { width: 100%; border-collapse: collapse; }
        table.data td { padding: 3px 2px; vertical-align: top; }
        table.data td.label { width: 36%; }
        table.data td.sep { width: 3%; }
        .jumlah { margin-top: 8px; border-top: 1px solid #000; border-bottom: 1px solid #000; padding: 6px 2px; }
        .jumlah .nominal { font-size: 14pt; font-weight: bold; }
        .jumlah .kata { font-style: italic; margin-top: 2px; }
        .lunas-mini { margin: 8px 0; text-align: center; border: 2px solid #1a7f37; color: #1a7f37; padding: 6px;
                      font-weight: bold; font-size: 12pt; letter-spacing: 1px; }
        .stempel { position: absolute; right: 16mm; bottom: 14mm; border: 3px solid #1a7f37; color: #1a7f37; padding: 2px 10px;
                   font-size: 14pt; font-weight: bold; transform: rotate(-12deg); opacity: .75; letter-spacing: 2px; }
        .ttd-section { margin-top: 10px; display: flex; justify-content: space-around; }
        .ttd-box { text-align: center; width: 40%; }
        .ttd-line { border-top: 1px solid #000; margin-top: 42px; padding-top: 3px; }
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
            <h1>BUKTI PEMBAYARAN ANGSURAN</h1>
            <h2>{{ $cooperative->nama ?? 'Koperasi' }}</h2>
            <p>{{ $cooperative->alamat ?? '-' }}, Telp: {{ $cooperative->telepon ?? '-' }}</p>
        </div>
        @unless($pinjamanLunas)
            <div class="stempel">LUNAS</div>
        @endunless

        <div class="cols">
            <div>
                <table class="data">
                    <tr><td class="label">Nama Peminjam</td><td class="sep">:</td><td>{{ $loan->nama_lengkap }} ({{ $loan->kode_anggota }})</td></tr>
                    <tr><td class="label">Alamat</td><td class="sep">:</td><td>{{ $loan->alamat ?: '-' }}</td></tr>
                    <tr><td class="label">Kode Pinjaman</td><td class="sep">:</td><td>{{ $loan->kode_pinjaman }}</td></tr>
                </table>
            </div>
            <div>
                <table class="data">
                    <tr><td class="label">Jenis Angsuran</td><td class="sep">:</td><td>{{ T::frequency($loan->frekuensi ?? 'bulanan') }}</td></tr>
                    <tr><td class="label">Angsuran</td><td class="sep">:</td><td>{{ $ketAngsuran }}</td></tr>
                    <tr><td class="label">Tanggal Bayar</td><td class="sep">:</td><td>{{ T::dateId($payment->tanggal_bayar) }}</td></tr>
                    <tr><td class="label">Jatuh Tempo</td><td class="sep">:</td><td>{{ T::dateId($payment->tanggal_jatuh_tempo) }}</td></tr>
                </table>
            </div>
        </div>

        @if($pinjamanLunas)
            <div class="lunas-mini">LUNAS</div>
        @else
            <div class="jumlah">
                <table class="data">
                    <tr><td class="label">Jumlah Angsuran</td><td class="sep">:</td><td>{{ T::rupiah($jumlahAngsuran) }}</td></tr>
                    @if($denda > 0)
                        <tr><td class="label">Denda</td><td class="sep">:</td><td>{{ T::rupiah($denda) }}</td></tr>
                    @endif
                    <tr><td class="label"><strong>Total Dibayar</strong></td><td class="sep">:</td><td class="nominal">{{ T::rupiah($totalBayar) }}</td></tr>
                    <tr><td class="label">Terbilang</td><td class="sep">:</td><td class="kata">{{ T::terbilangRupiah($totalBayar) }}</td></tr>
                    <tr><td class="label">Sisa Tagihan</td><td class="sep">:</td><td>{{ T::rupiah($sisaTagihan) }}</td></tr>
                </table>
            </div>
        @endif

        <div class="ttd-section">
            <div class="ttd-box"><div class="ttd-line"><strong>TELLER</strong><br>{!! $tellerNama !== '' ? e($tellerNama) : '&nbsp;' !!}</div></div>
        </div>
    </div>
</body>
</html>
