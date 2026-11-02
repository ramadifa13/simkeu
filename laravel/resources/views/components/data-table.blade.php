@props(['columns' => [], 'sortable' => true, 'mobileHidden' => []])

<div {{ $attributes->merge(['class' => 'overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm']) }}>
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
