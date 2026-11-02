@props(['label', 'name', 'accept' => null, 'required' => false, 'description' => null])

<flux:field>
    <flux:label>{{ $label }}</flux:label>
    <flux:input type="file" name="{{ $name }}" :accept="$accept" :required="$required" {{ $attributes }} />
    @if ($description)<flux:description>{{ $description }}</flux:description>@endif
    <flux:error name="{{ $name }}" />
</flux:field>
