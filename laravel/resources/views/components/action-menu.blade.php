@props(['label' => 'Aksi'])
@php($menuId = 'action-menu-'.str_replace('.', '', uniqid('', true)))

<div class="ui-action-menu inline-block text-left">
    <button
        type="button"
        class="ui-action-trigger"
        data-action-trigger
        popovertarget="{{ $menuId }}"
        aria-label="{{ $label }}"
        aria-haspopup="menu"
        aria-expanded="false"
        title="{{ $label }}"
    ><span aria-hidden="true">&#8943;</span></button>
    <div
        id="{{ $menuId }}"
        class="ui-action-popover"
        data-action-popover
        popover="auto"
        role="menu"
    >{{ $slot }}</div>
</div>
