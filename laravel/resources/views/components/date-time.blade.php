@props(['value', 'format' => 'd M Y H:i'])

@if ($value)
    <time datetime="{{ \Illuminate\Support\Carbon::parse($value)->toIso8601String() }}">{{ \Illuminate\Support\Carbon::parse($value)->translatedFormat($format) }}</time>
@else
    <span>—</span>
@endif
