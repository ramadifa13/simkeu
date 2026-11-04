{{-- Bridge session flash ke Flux toast (layout harus punya <flux:toast />). --}}
@if (session()->has('status') || session()->has('error'))
    <div
        x-data
        x-init="
            queueMicrotask(() => {
                @if (session('status'))
                    $flux.toast({
                        heading: 'Berhasil',
                        text: {{ \Illuminate\Support\Js::from(session('status')) }},
                        variant: 'success',
                        duration: 5000,
                    });
                @endif
                @if (session('error'))
                    $flux.toast({
                        heading: 'Tidak dapat diproses',
                        text: {{ \Illuminate\Support\Js::from(session('error')) }},
                        variant: 'danger',
                        duration: 10000,
                    });
                @endif
            });
        "
        class="hidden"
        aria-hidden="true"
    ></div>
@endif
