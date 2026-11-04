@props([
    'name' => 'bulan',
    'label' => 'Bulan',
    'value' => null,
    'max' => null,
    'min' => null,
    'monthsBack' => 36,
    'required' => false,
])

@php
    $selected = (string) old($name, $value ?? now()->format('Y-m'));
    $maxMonth = filled($max)
        ? \Carbon\Carbon::createFromFormat('Y-m', $max)->startOfMonth()
        : now()->startOfMonth();
    $minMonth = filled($min)
        ? \Carbon\Carbon::createFromFormat('Y-m', $min)->startOfMonth()
        : $maxMonth->copy()->subMonths(max(0, (int) $monthsBack));

    if ($minMonth->gt($maxMonth)) {
        [$minMonth, $maxMonth] = [$maxMonth->copy(), $minMonth->copy()];
    }

    $monthNames = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
    ];
    $formatMonth = static function (string $ym) use ($monthNames): string {
        try {
            $date = \Carbon\Carbon::createFromFormat('Y-m', $ym)->startOfMonth();
        } catch (\Throwable) {
            return $ym;
        }

        return ($monthNames[(int) $date->month] ?? $date->format('m')).' '.$date->year;
    };

    $options = [];
    $cursor = $maxMonth->copy();
    while ($cursor->gte($minMonth)) {
        $key = $cursor->format('Y-m');
        $options[$key] = $formatMonth($key);
        $cursor->subMonthNoOverflow();
    }

    if ($selected !== '' && ! array_key_exists($selected, $options)) {
        $options = [$selected => $formatMonth($selected), ...$options];
    }
@endphp

<flux:field {{ $attributes }}>
    <flux:label>
        {{ $label }}
        @if ($required)
            <x-required-mark />
        @endif
    </flux:label>
    <flux:select :name="$name" :required="$required">
        @foreach ($options as $ym => $monthLabel)
            <option value="{{ $ym }}" @selected($selected === $ym)>{{ $monthLabel }}</option>
        @endforeach
    </flux:select>
    <flux:error :name="$name" />
</flux:field>
