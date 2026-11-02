@props(['name', 'title', 'description' => null])

<flux:modal name="{{ $name }}" variant="flyout" position="right"><div class="flex h-full flex-col"><div class="pr-10"><flux:heading size="lg">{{ $title }}</flux:heading>@if($description)<flux:subheading>{{ $description }}</flux:subheading>@endif</div><div class="mt-6">{{ $slot }}</div></div></flux:modal>
