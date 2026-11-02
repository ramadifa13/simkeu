@props(['title' => 'Belum ada data', 'description' => null, 'icon' => 'folder-off'])

<div {{ $attributes->merge(['class' => 'ui-empty-state']) }}>
    <span class="ui-empty-icon"><flux:icon :name="$icon" class="size-6" /></span>
    <p class="mt-4 font-semibold text-slate-800">{{ $title }}</p>
    @if ($description)<p class="mx-auto mt-2 max-w-md text-sm leading-6 text-slate-500">{{ $description }}</p>@endif
    @if (isset($action))<div class="mt-5">{{ $action }}</div>@endif
</div>
