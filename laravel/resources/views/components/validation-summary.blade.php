@if ($errors->any())
    <flux:callout variant="danger" icon="exclamation-triangle">
        <flux:callout.heading>Periksa kembali isian formulir.</flux:callout.heading>
        <flux:callout.text>
            <ul class="list-disc space-y-1 pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </flux:callout.text>
    </flux:callout>
@endif
