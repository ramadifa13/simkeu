@props(['name', 'title', 'description' => null])

<flux:modal name="{{ $name }}" variant="flyout" position="bottom"><div class="mx-auto max-w-xl"><div class="pr-10"><flux:heading size="lg">{{ $title }}</flux:heading>@if($description)<flux:subheading>{{ $description }}</flux:subheading>@endif</div><div class="mt-5">{{ $slot }}</div></div></flux:modal>
