@props(['name' => 'search', 'value' => '', 'placeholder' => 'Cari data…'])

<flux:input type="search" name="{{ $name }}" value="{{ $value }}" placeholder="{{ $placeholder }}" {{ $attributes }} />
