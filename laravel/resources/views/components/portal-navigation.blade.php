@props(['role', 'mobile' => false])
@php
    $items = config("navigation.{$role}", []);
    $routeName = request()->route()?->getName() ?? '';
    $module = request()->route('module');
    $loanCartCount = in_array($role, ['admin', 'teller'], true)
        ? count(session('loan_installment_cart', []))
        : 0;

    $badgeFor = function (?string $badge) use ($loanCartCount): ?int {
        return match ($badge) {
            'loan_cart' => $loanCartCount > 0 ? $loanCartCount : null,
            default => null,
        };
    };

    $isCurrent = function (array $entry) use ($routeName, $module): bool {
        if (isset($entry['active_when'])) {
            foreach ($entry['active_when'] as $pattern) {
                if ($routeName === $pattern || str_starts_with($routeName, $pattern.'.')) {
                    return true;
                }
            }

            return false;
        }

        if (isset($entry['route'])) {
            if (isset($entry['params'])) {
                foreach ($entry['params'] as $key => $value) {
                    if ((string) request()->query($key) !== (string) $value) {
                        return false;
                    }
                }
            }

            if (($entry['match'] ?? null) === 'exact') {
                return $routeName === $entry['route'];
            }

            if ($routeName === $entry['route'] || str_starts_with($routeName, $entry['route'].'.')) {
                return true;
            }

            if (str_ends_with($entry['route'], '.index')) {
                return str_starts_with($routeName, substr($entry['route'], 0, -strlen('.index')).'.');
            }

            return false;
        }

        return isset($entry['module']) && $module === $entry['module'];
    };

    $navHref = function (array $entry) use ($role): string {
        if (isset($entry['route'])) {
            return route($entry['route'], ['role' => $role, ...($entry['params'] ?? [])]);
        }

        return route('portal.modules.placeholder', ['role' => $role, 'module' => $entry['module']]);
    };
@endphp

<nav {{ $attributes->merge(['class' => 'sidebar-nav']) }} aria-label="Navigasi portal">
    @foreach ($items as $item)
        @if (isset($item['items']))
            @php
                $groupActive = collect($item['items'])->contains(function (array $child) use ($isCurrent): bool {
                    if ($isCurrent($child)) {
                        return true;
                    }

                    if (! isset($child['items'])) {
                        return false;
                    }

                    return collect($child['items'])->contains(fn (array $grandchild): bool => $isCurrent($grandchild));
                });
            @endphp

            <details class="sidebar-group" data-nav-group {!! $groupActive ? 'open data-nav-active' : '' !!}>
                <summary class="sidebar-link{{ $groupActive ? ' is-active' : '' }}" title="{{ $item['label'] }}" aria-label="{{ $item['label'] }}">
                    <flux:icon :name="$item['icon']" class="sidebar-link-icon" />
                    <span class="sidebar-link-label sidebar-hide-collapsed">{{ $item['label'] }}</span>
                    <flux:icon.chevron-down class="sidebar-link-chevron sidebar-hide-collapsed" />
                </summary>

                <div class="sidebar-subnav sidebar-hide-collapsed">
                    @foreach ($item['items'] as $child)
                        @if (isset($child['items']))
                            @php
                                $childActive = collect($child['items'])->contains(fn (array $grandchild): bool => $isCurrent($grandchild));
                            @endphp

                            <details class="sidebar-subgroup" {!! $childActive ? 'open' : '' !!}>
                                <summary class="sidebar-sublink{{ $childActive ? ' is-current' : '' }}" aria-label="{{ $child['label'] }}">
                                    <span>{{ $child['label'] }}</span>
                                    <flux:icon.chevron-down class="size-3" />
                                </summary>

                                <div class="sidebar-subnav sidebar-subnav-nested">
                                    @foreach ($child['items'] as $grandchild)
                                        @php
                                            $href = $navHref($grandchild);
                                            $badge = $badgeFor($grandchild['badge'] ?? null);
                                            $current = $isCurrent($grandchild);
                                        @endphp

                                        <a href="{{ $href }}" class="sidebar-sublink{{ $current ? ' is-current' : '' }}" {!! $current ? 'aria-current="page"' : '' !!}>
                                            <span>{{ $grandchild['label'] }}</span>
                                            @if ($badge !== null)
                                                <span class="sidebar-nav-badge">{{ $badge }}</span>
                                            @endif
                                        </a>
                                    @endforeach
                                </div>
                            </details>
                        @else
                            @php
                                $href = $navHref($child);
                                $badge = $badgeFor($child['badge'] ?? null);
                                $current = $isCurrent($child);
                            @endphp

                            <a href="{{ $href }}" class="sidebar-sublink{{ $current ? ' is-current' : '' }}" {!! $current ? 'aria-current="page"' : '' !!}>
                                <span>{{ $child['label'] }}</span>
                                @if ($badge !== null)
                                    <span class="sidebar-nav-badge">{{ $badge }}</span>
                                @endif
                            </a>
                        @endif
                    @endforeach
                </div>
            </details>
        @else
            @php
                $href = $navHref($item);
                $current = $isCurrent($item);
            @endphp

            <a href="{{ $href }}" title="{{ $item['label'] }}" class="sidebar-link{{ $current ? ' is-current' : '' }}" {!! $current ? 'aria-current="page"' : '' !!}>
                <flux:icon :name="$item['icon']" class="sidebar-link-icon" />
                <span class="sidebar-link-label sidebar-hide-collapsed">{{ $item['label'] }}</span>
            </a>
        @endif
    @endforeach
</nav>
