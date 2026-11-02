@props(['items' => [], 'active' => null])

<nav {{ $attributes->merge(['class' => 'ui-tabs']) }} aria-label="Navigasi konten">
    @foreach ($items as $item)
        @php
            $label = is_array($item) ? data_get($item, 'label') : $item;
            $href = is_array($item) ? data_get($item, 'href') : null;
            $isActive = is_array($item) ? data_get($item, 'active', $label === $active) : $label === $active;
        @endphp

        @if ($href)
            <a href="{{ $href }}" @class(['is-active' => $isActive]) @if ($isActive) aria-current="page" @endif>{{ $label }}</a>
        @else
            <button type="button" @class(['is-active' => $isActive]) @if ($isActive) aria-current="page" @endif>{{ $label }}</button>
        @endif
    @endforeach
</nav>
