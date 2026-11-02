@props(['label' => 'Aksi'])

<div class="ui-action-menu inline-block text-left">
    <button type="button" class="ui-action-trigger" data-action-trigger aria-label="{{ $label }}" aria-haspopup="menu" aria-expanded="false" title="{{ $label }}"><span aria-hidden="true">&#8943;</span></button>
    <div class="ui-action-popover" data-action-popover popover="auto" role="menu">{{ $slot }}</div>
</div>
