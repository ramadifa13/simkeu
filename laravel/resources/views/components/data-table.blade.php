@props(['columns' => [], 'sortable' => true, 'mobileHidden' => [], 'title' => null, 'count' => null])

<div {{ $attributes->merge(['class' => 'ui-table-shell']) }}>
    @if ($title)
        <div class="ui-table-heading">
            <div class="ui-table-title"><span class="ui-table-title-mark"><flux:icon name="users" class="size-4" /></span>{{ $title }} @if (!is_null($count))<span class="ui-table-count">{{ $count }} data</span>@endif</div>
            @if (isset($tableActions))<div>{{ $tableActions }}</div>@endif
        </div>
    @endif
    @if (isset($filters))
        <div class="ui-table-filters-slot">{{ $filters }}</div>
    @endif
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200 text-left text-sm">
            <thead class="bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-500">
                <tr>
                    @foreach ($columns as $key => $label)
                        <th scope="col" class="whitespace-nowrap px-5 py-3 {{ in_array($key, $mobileHidden, true) ? 'hidden md:table-cell' : '' }}">
                            @if ($sortable && $key !== 'actions')
                                <button type="button" wire:click="sort('{{ $key }}')" class="inline-flex items-center gap-1 hover:text-slate-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-600">{{ $label }} <span aria-hidden="true">↕</span></button>
                            @else
                                {{ $label }}
                            @endif
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">{{ $slot }}</tbody>
        </table>
    </div>
</div>
