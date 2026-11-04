@props(['columns' => [], 'sortable' => true, 'mobileHidden' => [], 'title' => null, 'count' => null, 'loadingTarget' => null, 'icon' => 'users'])

<div {{ $attributes->merge(['class' => 'ui-table-shell']) }}>
    @if ($title)
        <div class="ui-table-heading">
            <div class="ui-table-title"><span class="ui-table-title-mark"><flux:icon :name="$icon" class="size-4" /></span>{{ $title }} @if (!is_null($count))<span class="ui-table-count">{{ $count }} data</span>@endif</div>
            @if (isset($tableActions))<div>{{ $tableActions }}</div>@endif
        </div>
    @endif
    @if (isset($filters))
        <div class="ui-table-filters-slot">{{ $filters }}</div>
    @endif
    @if (isset($loading))
        <div class="ui-table-loading-layer" wire:loading {!! $loadingTarget ? 'wire:target="'.e($loadingTarget).'"' : '' !!}>{{ $loading }}</div>
    @endif
    <div class="overflow-x-auto">
        <table class="w-full min-w-[38rem] divide-y divide-slate-200 text-left text-sm sm:min-w-[46rem] lg:min-w-full lg:table-fixed">
            <colgroup>
                @foreach ($columns as $key => $label)
                    <col class="{{ match ($key) {
                        'kode_anggota' => 'w-[11%]',
                        'nama_lengkap' => 'w-[30%]',
                        'telepon' => 'w-[18%]',
                        'status' => 'w-[13%]',
                        'tanggal_daftar' => 'w-[16%]',
                        'pick' => 'w-[4%]',
                        'ke' => 'w-[8%]',
                        'jatuh_tempo' => 'w-[14%]',
                        'tagihan' => 'w-[13%]',
                        'denda' => 'w-[11%]',
                        'actions' => 'w-[18%]',
                        default => '',
                    } }}">
                @endforeach
            </colgroup>
            <thead class="bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-500">
                <tr>
                    @foreach ($columns as $key => $label)
                        <th scope="col" class="whitespace-nowrap px-5 py-3 {{ $key === 'actions' ? 'text-right' : '' }} {{ in_array($key, $mobileHidden, true) ? 'hidden md:table-cell' : '' }}">
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
    @if (isset($footer))
        <div class="ui-table-footer">{{ $footer }}</div>
    @endif
</div>
