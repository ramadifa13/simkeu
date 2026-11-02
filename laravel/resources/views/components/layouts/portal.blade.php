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
        <a href="#content" class="sr-only fixed left-4 top-4 z-50 rounded-lg bg-slate-950 px-4 py-2 font-semibold text-white focus:not-sr-only">Lewati ke konten utama</a>
        <div class="min-h-screen lg:grid lg:grid-cols-[17rem_1fr]">
            <aside class="hidden border-r border-slate-200 bg-slate-950 px-5 py-6 text-slate-100 lg:block">
                <a href="{{ route('portal.dashboard', ['role' => $role]) }}" class="flex items-center gap-3 font-semibold tracking-tight">
                    <span class="grid size-10 place-items-center rounded-xl bg-teal-300 text-lg font-black text-slate-950">S</span>
                    <span>SIMKEU <small class="block text-xs font-medium text-slate-400">Portal {{ ucfirst($role) }}</small></span>
                </a>
                <x-portal-navigation :role="$role" class="mt-4" />
            </aside>

            <div class="min-w-0">
                <header class="flex min-h-18 items-center justify-between border-b border-slate-200 bg-white px-4 py-3 sm:px-6 lg:px-10">
                    <div class="flex min-w-0 items-center gap-3">
                        <details class="relative lg:hidden">
                            <summary class="grid size-10 cursor-pointer place-items-center rounded-lg border border-slate-200 text-slate-700 hover:bg-slate-50" aria-label="Buka menu navigasi">☰</summary>
                            <div class="absolute left-0 z-30 mt-2 w-72 rounded-2xl border border-slate-200 bg-white p-2 shadow-xl"><x-portal-navigation :role="$role" :mobile="true" /></div>
                        </details>
                        <div class="min-w-0"><p class="truncate text-sm font-medium text-slate-500">{{ $title }}</p>@if ($breadcrumbs)<x-breadcrumbs :items="$breadcrumbs" class="mt-1" />@endif</div>
                    </div>
                    <div class="flex items-center gap-3">
                        <details class="relative">
                            <summary class="grid size-10 cursor-pointer place-items-center rounded-lg border border-slate-200 text-slate-700 hover:bg-slate-50" aria-label="Buka notifikasi">🔔</summary>
                            <div class="absolute right-0 z-30 mt-2 w-72 rounded-2xl border border-slate-200 bg-white p-4 shadow-xl"><p class="font-semibold text-slate-900">Notifikasi</p><p class="mt-2 text-sm leading-6 text-slate-500">Belum ada notifikasi baru. Notifikasi operasional akan dihubungkan saat modul terkait tersedia.</p></div>
                        </details>
                        <div class="hidden text-right sm:block"><p class="text-sm font-semibold text-slate-900">{{ $user?->nama_lengkap }}</p><p class="text-xs text-slate-500">{{ ucfirst($role) }}</p></div>
                        <a href="{{ route('profile.edit') }}" class="hidden text-sm font-semibold text-teal-700 hover:text-teal-800 sm:block">Akun</a>
                        <form method="POST" action="{{ route('logout') }}">@csrf<flux:button type="submit" variant="ghost">Keluar</flux:button></form>
                    </div>
                </header>
                <main id="content" class="px-4 py-6 sm:px-6 lg:px-10 lg:py-8">{{ $slot }}</main>
            </div>
        </div>
        <div class="sr-only" aria-live="polite" aria-atomic="true"></div>
        <flux:toast position="bottom end" />
        @livewireScripts
        @fluxScripts
    </body>
</html>
