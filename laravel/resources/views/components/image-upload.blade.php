@props(['label', 'name', 'required' => false, 'description' => 'Gambar dioptimalkan otomatis menjadi maksimal 1 MB saat disimpan.'])

<div data-image-upload {{ $attributes->merge(['class' => 'ui-image-upload']) }}>
    <label for="{{ $name }}" class="mb-2 block text-sm font-semibold text-slate-800">{{ $label }}</label>
    <input id="{{ $name }}" type="file" name="{{ $name }}" accept="image/*" @required($required) class="block w-full rounded-xl border border-slate-300 bg-white p-2.5 text-sm text-slate-800 file:mr-3 file:rounded-lg file:border-0 file:bg-emerald-50 file:px-3 file:py-2 file:font-semibold file:text-emerald-800" />
    <input type="file" accept="image/*" capture="environment" data-camera-input tabindex="-1" aria-hidden="true" class="hidden" />
    <div class="ui-image-upload-options lg:hidden"><button type="button" data-camera-button>Ambil dari kamera</button><button type="button" data-gallery-button>Pilih dari galeri</button></div>
    <p class="mt-2 text-xs leading-5 text-slate-600">{{ $description }}</p>
    <p class="mt-1 text-xs font-medium text-emerald-800" data-upload-message aria-live="polite"></p>
    @error($name)<p class="mt-1 text-xs font-semibold text-rose-700">{{ $message }}</p>@enderror
</div>
