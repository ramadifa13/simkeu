<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $title ?? 'Dokumen' }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #111; margin: 24px; }
        h1 { font-size: 18px; margin: 0 0 4px; }
        h2 { font-size: 14px; margin: 16px 0 8px; }
        .muted { color: #555; }
        .box { border: 1px solid #ccc; padding: 12px; margin-top: 12px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #ccc; padding: 6px 8px; text-align: left; }
        th { background: #f3f3f3; }
        .right { text-align: right; }
        .total { font-size: 16px; font-weight: bold; }
    </style>
</head>
<body>
    <div>
        <h1>{{ $cooperative->nama ?? 'Koperasi' }}</h1>
        <p class="muted">{{ $cooperative->alamat ?? '' }} @if(!empty($cooperative->telepon)) · {{ $cooperative->telepon }} @endif</p>
    </div>
    {{ $slot }}
</body>
</html>
