@props(['filename', 'progress' => 0, 'error' => null])

<div {{ $attributes->merge(['class' => 'ui-upload-preview']) }}>
    <div class="flex items-center justify-between gap-3"><span>{{ $filename }}</span><span>{{ $progress }}%</span></div>
    <div class="ui-upload-progress"><span style="width: {{ max(0, min(100, (int) $progress)) }}%"></span></div>
    @if ($error)<p><flux:icon.exclamation-triangle class="size-3.5" /> {{ $error }}</p>@endif
</div>
