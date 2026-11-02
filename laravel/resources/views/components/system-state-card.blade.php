@props(['code', 'title', 'description', 'actionLabel' => 'Kembali aman'])

<div {{ $attributes->merge(['class' => 'ui-system-card']) }}>
    <span>{{ $code }}</span><h3>{{ $title }}</h3><p>{{ $description }}</p><flux:button size="sm" variant="outline">{{ $actionLabel }}</flux:button>
</div>
