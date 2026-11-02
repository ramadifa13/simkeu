@props(['name', 'title', 'description' => null])

<flux:modal name="{{ $name }}" class="max-w-lg">
    <div class="space-y-5">
        <div>
            <flux:heading size="lg">{{ $title }}</flux:heading>
            @if ($description)<flux:subheading>{{ $description }}</flux:subheading>@endif
        </div>
        {{ $slot }}
    </div>
</flux:modal>
