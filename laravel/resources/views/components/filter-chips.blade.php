@props(['label' => 'Filter aktif', 'filters' => [], 'resetLabel' => 'Reset semua'])

@if (count($filters))
    <div {{ $attributes->merge(['class' => 'ui-filter-chips']) }}>
        <span>{{ $label }}:</span>
        @foreach ($filters as $filter)
            <button type="button">{{ $filter }} <span aria-hidden="true">×</span></button>
        @endforeach
        <button type="button" class="ui-filter-clear">{{ $resetLabel }}</button>
    </div>
@endif
