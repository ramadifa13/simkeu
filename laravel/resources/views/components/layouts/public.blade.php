<!DOCTYPE html>
<html lang="id" class="bg-slate-950">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>{{ $title ?? 'SIMKEU' }}</title>
        @fluxAppearance
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
    </head>
    <body class="min-h-screen bg-slate-950 font-sans text-slate-100 antialiased">
        <a href="#content" class="sr-only fixed left-4 top-4 z-50 rounded-lg bg-white px-4 py-2 font-semibold text-slate-950 focus:not-sr-only">Lewati ke konten utama</a>
        {{ $slot }}
        @livewireScripts
        @fluxScripts
    </body>
</html>
