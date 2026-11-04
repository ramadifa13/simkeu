<!DOCTYPE html>
<html lang="id"><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;font-size:10px;margin:20px}h1{font-size:16px}table{width:100%;border-collapse:collapse;margin-top:10px}th,td{border:1px solid #ccc;padding:4px}th{background:#f3f3f3}</style></head><body>
<h1>{{ $cooperative->nama ?? 'Koperasi' }} — Laporan {{ $jenis }}</h1>
<p>Dicetak {{ now()->translatedFormat('d M Y H:i') }} · {{ $rows->count() }} baris</p>
<table>
    <thead><tr>@foreach(($rows->first() ? array_keys((array) $rows->first()) : []) as $col)<th>{{ $col }}</th>@endforeach</tr></thead>
    <tbody>
        @foreach($rows as $row)
            <tr>@foreach((array) $row as $value)<td>{{ is_scalar($value) ? $value : json_encode($value) }}</td>@endforeach</tr>
        @endforeach
    </tbody>
</table>
</body></html>
