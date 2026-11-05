@props(['role', 'title' => 'SIMKEU', 'breadcrumbs' => []])
@php
    $user = auth()->user();
    // Cache plain arrays only — serializing Collection caused incomplete-object 500s.
    // Bust token invalidates every role inbox after mutations (RecordActivity).
    $portalNotifications = collect();
    if ($user) {
        $bust = app(\App\Actions\Notifications\BustPortalNotificationCache::class)->token();
        $notifKey = 'portal-notif:'.$bust.':'.$user->id.':'.$role;
        $cached = cache()->get($notifKey);
        if (is_array($cached)) {
            $portalNotifications = collect($cached);
        } else {
            if ($cached !== null) {
                cache()->forget($notifKey);
            }
            $portalNotifications = app(\App\Actions\Notifications\GetPortalNotifications::class)->handle($user, $role);
            cache()->put($notifKey, $portalNotifications->values()->all(), 60);
        }
    }
@endphp
<!DOCTYPE html>
<html lang="id" class="bg-[#f3f6fb]">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title }}</title>
    @fluxAppearance
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="ui-app-shell min-h-screen font-sans text-slate-900 antialiased">
    <a href="#content" class="sr-only fixed left-4 top-4 z-50 rounded-lg bg-slate-950 px-4 py-2 font-semibold text-white focus:not-sr-only">Lewati ke konten utama</a>
    <div class="min-h-screen lg:grid lg:grid-cols-[var(--sidebar-width)_minmax(0,1fr)]" data-portal-shell>
        <script>
            (() => {
                const shell = document.currentScript?.parentElement;
                if (shell && localStorage.getItem('simkeu-sidebar-collapsed') === 'true') {
                    shell.classList.add('sidebar-collapsed');
                }
            })();
        </script>
        <aside class="sidebar-panel fixed inset-y-0 left-0 z-40 hidden w-[var(--sidebar-width)] flex-col lg:flex" data-sidebar>
            <div class="sidebar-panel-top">
                <x-sidebar.brand
                    :href="route('portal.dashboard', ['role' => $role])"
                    :subtitle="'Portal '.ucfirst($role)"
                />
            </div>
            <x-portal-navigation :role="$role" class="sidebar-panel-nav sidebar-scroll" />
            <div class="sidebar-panel-bottom">
                <a
                    href="{{ route('profile.edit') }}"
                    title="{{ $user?->nama_lengkap }} · Kelola akun"
                    class="sidebar-account"
                >
                    <span class="sidebar-account-avatar">{{ mb_substr($user?->nama_lengkap ?? 'U', 0, 1) }}</span>
                    <span class="sidebar-account-copy sidebar-hide-collapsed">
                        <span class="sidebar-account-name">{{ $user?->nama_lengkap }}</span>
                        <span class="sidebar-account-meta">Kelola akun</span>
                    </span>
                </a>
            </div>
        </aside>
        <div class="min-w-0 lg:col-start-2">
            <header class="sticky top-0 z-30 flex min-h-18 items-center justify-between px-4 py-3 sm:px-6 lg:px-8">
                <div class="flex min-w-0 items-center gap-3">
                    <button type="button" class="ui-icon-button grid size-10 place-items-center rounded-xl border text-lg text-slate-700 transition hover:-translate-y-0.5 lg:hidden" data-mobile-nav-toggle aria-label="Buka menu navigasi" aria-expanded="false">&#9776;</button>
                    <button type="button" class="ui-icon-button hidden size-10 place-items-center rounded-xl border text-slate-700 transition hover:-translate-y-0.5 lg:grid" data-sidebar-toggle aria-label="Ciutkan sidebar" aria-expanded="true">&#9776;</button>
                    <div class="min-w-0">
                        <p class="truncate text-sm font-semibold text-slate-700">{{ $title }}</p>
                        @if ($breadcrumbs)<x-breadcrumbs :items="$breadcrumbs" class="mt-1" />@endif
                    </div>
                </div>
                <div class="flex items-center gap-2 sm:gap-3">
                    <x-portal-notifications :items="$portalNotifications" :role="$role" />
                    <details class="relative" data-dismissible-menu>
                        <summary class="flex cursor-pointer list-none items-center gap-2 rounded-xl px-2 py-1.5 transition hover:bg-white/80" aria-label="Menu akun {{ $user?->nama_lengkap ?? 'pengguna' }}">
                            <span class="grid size-8 place-items-center rounded-full bg-gradient-to-br from-teal-400 to-sky-500 text-xs font-bold text-white">{{ mb_substr($user?->nama_lengkap ?? 'U', 0, 1) }}</span>
                            <span class="hidden max-w-32 truncate text-left text-sm sm:block">
                                <span class="block font-semibold text-slate-900">{{ $user?->nama_lengkap }}</span>
                                <span class="block text-xs text-slate-500">{{ ucfirst($role) }}</span>
                            </span>
                            <flux:icon.chevron-down class="hidden size-4 text-slate-400 sm:block" />
                        </summary>
                        <div class="absolute right-0 z-30 mt-3 w-64 rounded-3xl border border-slate-200 bg-white p-3 shadow-2xl shadow-slate-900/15">
                            <a href="{{ route('profile.edit') }}" class="flex items-center gap-3 rounded-2xl p-3 transition hover:bg-teal-50">
                                <span class="grid size-10 place-items-center rounded-full bg-gradient-to-br from-teal-400 to-sky-500 font-bold text-white">{{ mb_substr($user?->nama_lengkap ?? 'U', 0, 1) }}</span>
                                <span>
                                    <span class="block font-semibold text-slate-900">{{ $user?->nama_lengkap }}</span>
                                    <span class="block text-xs text-slate-500">Kelola profil dan keamanan</span>
                                </span>
                            </a>
                            <div class="my-2 border-t border-slate-100"></div>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <flux:button type="submit" variant="ghost" icon="arrow-right-start-on-rectangle" class="w-full justify-start! text-rose-700! hover:bg-rose-50! hover:text-rose-800!">Keluar</flux:button>
                            </form>
                        </div>
                    </details>
                </div>
            </header>
            <main id="content" class="animate-rise-in px-4 py-6 sm:px-6 lg:px-8 lg:py-8">{{ $slot }}</main>
        </div>
    </div>
    <div class="fixed inset-0 z-40 hidden bg-slate-950/50 backdrop-blur-sm lg:hidden" data-mobile-nav-backdrop></div>
    <aside class="sidebar-panel sidebar-panel-mobile fixed inset-y-0 left-0 z-50 flex w-[min(20rem,86vw)] -translate-x-full flex-col shadow-2xl transition-transform duration-300 lg:hidden" data-mobile-nav aria-label="Navigasi portal mobile">
        <div class="sidebar-panel-top sidebar-panel-top-mobile">
            <x-sidebar.brand subtitle="Menu portal" class="min-w-0 flex-1" />
            <button type="button" class="sidebar-mobile-close" data-mobile-nav-toggle aria-label="Tutup menu">&times;</button>
        </div>
        <x-portal-navigation :role="$role" :mobile="true" class="sidebar-panel-nav sidebar-scroll" />
    </aside>
    <div class="sr-only" aria-live="polite" aria-atomic="true"></div>
    <x-flash-status />
    @persist('toast')
        <flux:toast position="bottom end" />
    @endpersist
    @livewireScripts
    @fluxScripts
</body>
</html>
