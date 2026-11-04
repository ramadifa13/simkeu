@props(['items' => collect(), 'role' => 'pengguna'])
@php
    $items = collect($items)->values();
    $actionableCount = $items->where('actionable', true)->count();
    $totalCount = $items->count();
    $roleLabel = ['admin' => 'Admin', 'teller' => 'Teller', 'anggota' => 'Anggota'][$role] ?? ucfirst($role);
    $toneClasses = [
        'amber' => 'portal-notif-tone-amber',
        'blue' => 'portal-notif-tone-blue',
        'rose' => 'portal-notif-tone-rose',
        'cyan' => 'portal-notif-tone-cyan',
        'violet' => 'portal-notif-tone-violet',
        'emerald' => 'portal-notif-tone-emerald',
    ];
@endphp

<details class="portal-notif relative" data-dismissible-menu data-portal-notif>
    <summary
        class="ui-icon-button portal-notif-trigger relative grid size-10 cursor-pointer list-none place-items-center rounded-xl border text-slate-700 transition hover:border-teal-300 hover:bg-teal-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-700 [&::-webkit-details-marker]:hidden"
        aria-label="Notifikasi{{ $actionableCount ? ', '.$actionableCount.' perlu tindakan' : ($totalCount ? ', '.$totalCount.' pembaruan' : '') }}"
        aria-haspopup="true"
        aria-expanded="false"
        data-portal-notif-trigger
    >
        <flux:icon name="bell" class="size-5" />
        @if ($actionableCount > 0)
            <span class="portal-notif-badge" aria-hidden="true">{{ $actionableCount > 9 ? '9+' : $actionableCount }}</span>
        @elseif ($totalCount > 0)
            <span class="portal-notif-badge portal-notif-badge-muted" aria-hidden="true"></span>
        @endif
    </summary>

    <div class="portal-notif-scrim" data-portal-notif-scrim hidden aria-hidden="true"></div>

    <section class="portal-notif-panel" aria-label="Daftar notifikasi {{ strtolower($roleLabel) }}">
        <header class="portal-notif-head">
            <div class="portal-notif-head-main">
                <span class="portal-notif-head-icon" aria-hidden="true">
                    <flux:icon name="bell-alert" class="size-5" />
                </span>
                <div class="min-w-0">
                    <h2 class="portal-notif-title">Notifikasi</h2>
                    <p class="portal-notif-sub">Aktivitas pinjaman untuk {{ $roleLabel }}.</p>
                </div>
            </div>
            <span class="portal-notif-count">
                @if ($actionableCount > 0)
                    {{ $actionableCount }} perlu tindakan
                @else
                    {{ $totalCount }} terbaru
                @endif
            </span>
        </header>

        <div class="portal-notif-list">
            @forelse ($items as $item)
                <a href="{{ $item['href'] }}" class="portal-notif-item{{ ! empty($item['actionable']) ? ' is-actionable' : '' }}">
                    <span class="portal-notif-avatar {{ $toneClasses[$item['tone']] ?? 'portal-notif-tone-slate' }}">
                        <flux:icon :name="$item['icon']" class="size-4" />
                    </span>
                    <span class="portal-notif-body">
                        <span class="portal-notif-row-top">
                            <span class="portal-notif-label">{{ $item['title'] }}</span>
                            <span class="portal-notif-group">{{ $item['group'] }}</span>
                        </span>
                        <span class="portal-notif-meta">{{ $item['description'] }}</span>
                    </span>
                    <flux:icon name="chevron-right" class="portal-notif-chevron" />
                </a>
            @empty
                <div class="portal-notif-empty">
                    <span class="portal-notif-empty-icon" aria-hidden="true">
                        <flux:icon name="check" class="size-5" />
                    </span>
                    <p class="portal-notif-empty-title">Belum ada aktivitas</p>
                    <p class="portal-notif-empty-copy">Pemberitahuan yang sesuai dengan peran Anda akan tampil di sini.</p>
                </div>
            @endforelse
        </div>

        <footer class="portal-notif-foot">
            <a href="{{ route('portal.loans.index', ['role' => $role]) }}" class="portal-notif-foot-link">
                {{ $role === 'anggota' ? 'Lihat pinjaman saya' : 'Buka modul pinjaman' }}
                <flux:icon name="arrow-right" class="size-3.5" />
            </a>
        </footer>
    </section>
</details>
