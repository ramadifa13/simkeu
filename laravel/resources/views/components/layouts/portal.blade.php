@props(['role', 'title' => 'SIMKEU', 'breadcrumbs' => []])

@php($user = auth()->user())

<!DOCTYPE html>
<html lang="id" class="bg-slate-50">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>{{ $title }}</title>
        @fluxAppearance
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
    </head>
    <body class="min-h-screen bg-slate-50 font-sans text-slate-900 antialiased">
        <div class="min-h-screen lg:grid lg:grid-cols-[17rem_1fr]">
            <aside class="hidden border-r border-slate-200 bg-slate-950 px-5 py-6 text-slate-100 lg:block">
                <a href="{{ route('portal.dashboard', ['role' => $role]) }}" class="flex items-center gap-3 font-semibold tracking-tight">
                    <span class="grid size-10 place-items-center rounded-xl bg-teal-300 text-lg font-black text-slate-950">S</span>
                    <span>SIMKEU <small class="block text-xs font-medium text-slate-400">Portal {{ ucfirst($role) }}</small></span>
                </a>
                <nav class="mt-9 grid gap-1 text-sm" aria-label="Navigasi portal">
                    <a href="{{ route('portal.dashboard', ['role' => $role]) }}" class="rounded-xl px-3 py-2.5 font-semibold text-slate-300 transition hover:bg-white/10 hover:text-white">Dashboard</a>
                    @if (in_array($role, ['admin', 'teller'], true))
                        <a href="{{ route('portal.members.index', ['role' => $role]) }}" class="rounded-xl px-3 py-2.5 font-medium text-slate-300 transition hover:bg-white/10 hover:text-white">Data Anggota</a>
                    @endif
                    <span class="rounded-xl px-3 py-2.5 text-slate-500">Menu operasional menyusul per modul</span>
                </nav>
            </aside>

            <div class="min-w-0">
                <header class="flex min-h-18 items-center justify-between border-b border-slate-200 bg-white px-4 py-3 sm:px-6 lg:px-10">
                    <div class="flex min-w-0 items-center gap-3">
                        <details class="relative lg:hidden">
                            <summary class="grid size-10 cursor-pointer place-items-center rounded-lg border border-slate-200 text-slate-700 hover:bg-slate-50" aria-label="Buka menu navigasi">☰</summary>
                            <nav class="absolute left-0 z-30 mt-2 grid w-64 gap-1 rounded-2xl border border-slate-200 bg-white p-2 shadow-xl" aria-label="Navigasi portal mobile">
                                <a href="{{ route('portal.dashboard', ['role' => $role]) }}" class="rounded-xl px-3 py-2.5 text-sm font-semibold hover:bg-slate-50">Dashboard</a>
                                @if (in_array($role, ['admin', 'teller'], true))
                                    <a href="{{ route('portal.members.index', ['role' => $role]) }}" class="rounded-xl px-3 py-2.5 text-sm font-semibold hover:bg-slate-50">Data Anggota</a>
                                @endif
                            </nav>
                        </details>
                        <div class="min-w-0"><p class="truncate text-sm font-medium text-slate-500">{{ $title }}</p>@if ($breadcrumbs)<x-breadcrumbs :items="$breadcrumbs" class="mt-1" />@endif</div>
                    </div>
                    <div class="flex items-center gap-3">
                        <div class="hidden text-right sm:block"><p class="text-sm font-semibold text-slate-900">{{ $user?->nama_lengkap }}</p><p class="text-xs text-slate-500">{{ ucfirst($role) }}</p></div>
                        <form method="POST" action="{{ route('logout') }}">@csrf<flux:button type="submit" variant="ghost">Keluar</flux:button></form>
                    </div>
                </header>
                <main class="px-4 py-6 sm:px-6 lg:px-10 lg:py-8">{{ $slot }}</main>
            </div>
        </div>
        <div class="sr-only" aria-live="polite" aria-atomic="true"></div>
        <flux:toast position="bottom end" />
        @livewireScripts
        @fluxScripts
    </body>
</html>
