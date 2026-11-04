@props([
    'label',
    'name',
    'required' => false,
    'preserveAlpha' => false,
    'description' => null,
    'multiple' => false,
])

@php
    $description ??= $preserveAlpha
        ? 'PNG/WebP transparan disimpan apa adanya (maks. 1 MB) agar latar tetap bening.'
        : 'JPG, PNG, atau WebP. Gambar dioptimalkan otomatis menjadi maksimal 1 MB saat dipilih.';
    $accept = $preserveAlpha
        ? 'image/png,image/webp,.png,.webp'
        : 'image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp';
    $dynamicRequired = $attributes->get('x-bind:required') ?? $attributes->get('x-bind.required');
    $alpineAttributes = $attributes->whereStartsWith('x-');
    $wrapperAttributes = $attributes
        ->except(array_keys($alpineAttributes->getAttributes()))
        ->merge(['class' => 'ui-image-upload']);
@endphp

<div
    data-image-upload
    @if ($preserveAlpha) data-preserve-alpha @endif
    @if ($multiple) data-multiple @endif
    {{ $wrapperAttributes }}
>
    <label for="{{ $name }}" class="mb-2 block text-sm font-semibold text-slate-800">
        {{ $label }}
        @if ($required)
            <x-required-mark />
        @elseif (filled($dynamicRequired))
            <span x-cloak x-show="{{ $dynamicRequired }}"><x-required-mark /></span>
        @endif
    </label>
    <input
        id="{{ $name }}"
        type="file"
        name="{{ $name }}"
        accept="{{ $accept }}"
        @required($required)
        @if ($multiple) multiple @endif
        {{ $alpineAttributes }}
        class="block w-full rounded-xl border border-slate-300 bg-white p-2.5 text-sm text-slate-800 file:mr-3 file:rounded-lg file:border-0 file:bg-emerald-50 file:px-3 file:py-2 file:font-semibold file:text-emerald-800"
    />
    @unless ($multiple)
        <input type="file" accept="{{ $accept }}" capture="environment" data-camera-input tabindex="-1" aria-hidden="true" class="hidden" />
        <div class="ui-image-upload-options lg:hidden">
            <button type="button" data-camera-button>Ambil dari kamera</button>
            <button type="button" data-gallery-button>Pilih dari galeri</button>
        </div>
    @endunless
    <p class="mt-2 text-xs leading-5 text-slate-600">{{ $description }}</p>
    <p class="mt-1 text-xs font-medium text-emerald-800" data-upload-message aria-live="polite"></p>
    @error(str_replace('[]', '', $name))
        <p class="mt-1 text-xs font-semibold text-rose-700">{{ $message }}</p>
    @enderror
    @error($name)
        <p class="mt-1 text-xs font-semibold text-rose-700">{{ $message }}</p>
    @enderror
</div>
