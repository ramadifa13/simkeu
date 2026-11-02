@props(['paginator'])

@if ($paginator->hasPages())
    <div {{ $attributes->merge(['class' => 'mt-5']) }}>{{ $paginator->links() }}</div>
@endif
