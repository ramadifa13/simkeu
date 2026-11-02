@props(['label', 'items' => []])

<div>
    <p class="ui-preview-label">{{ $label }}</p>
    <div class="ui-installment-chips mt-2">
        @foreach ($items as $item)
            <button type="button" @class(['is-active' => data_get($item, 'active', false)])>{{ data_get($item, 'label') }} @if(data_get($item, 'date'))<small>{{ data_get($item, 'date') }}</small>@endif @if(!is_null(data_get($item, 'count')))<span>{{ data_get($item, 'count') }}</span>@endif</button>
        @endforeach
    </div>
</div>
