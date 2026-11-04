@props(['events' => []])

<ol {{ $attributes->merge(['class' => 'ui-audit-timeline space-y-0']) }}>
    @forelse ($events as $event)
        <li class="relative border-l pb-6 pl-6 last:border-l-0 last:pb-0">
            <span class="absolute -left-1.5 top-1 size-3 rounded-full"></span>
            <div class="flex flex-col gap-1 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <p class="font-semibold text-slate-900">{{ data_get($event, 'action', data_get($event, 'aksi', 'Aktivitas')) }}</p>
                    <p class="mt-1 text-sm leading-6 text-slate-600">{{ data_get($event, 'detail', '—') }}</p>
                    @if (data_get($event, 'actor'))<p class="mt-1 text-xs text-slate-500">Oleh {{ data_get($event, 'actor') }}</p>@endif
                </div>
                @if (data_get($event, 'created_at'))<x-date-time :value="data_get($event, 'created_at')" class="shrink-0 text-xs text-slate-500" />@endif
            </div>
        </li>
    @empty
        <li><x-empty-state title="Belum ada aktivitas audit" description="Aktivitas yang berkaitan dengan data ini akan tampil di sini." /></li>
    @endforelse
</ol>
