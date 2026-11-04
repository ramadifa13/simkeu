<x-layouts.portal :role="$role" title="Edit {{ $saving->nama_jenis }}">
    <div class="mx-auto max-w-4xl">
        <x-page-header icon="pencil-square" eyebrow="{{ $saving->kode_jenis }}" :title="'Edit '.$saving->nama_jenis" description="Perbarui batas, bagi hasil, atau status produk." :back-href="route('portal.master.index', ['role' => $role, 'tab' => 'simpanan'])" back-label="Jenis simpanan" />
        @if ($isReserved)
            <flux:callout variant="warning" icon="lock-closed" class="mt-6">
                <flux:callout.heading>Jenis sistem terlindungi</flux:callout.heading>
                <flux:callout.text>ANG, BONUS, dan SHU tidak dapat diubah dari master data agar alokasi otomatis tetap aman.</flux:callout.text>
            </flux:callout>
        @endif
        <form method="POST" action="{{ route('portal.master.savings.update', ['role' => $role, 'saving' => $saving]) }}" class="mt-6 space-y-5">
            @csrf @method('PUT')
            <x-validation-summary />
            <x-master.saving-form :saving="$saving" :editing="true" :locked="$isReserved" />
            @unless ($isReserved)
                <x-form-actions class="mt-6" note="Perubahan berlaku untuk transaksi baru." :cancel-href="route('portal.master.index', ['role' => $role, 'tab' => 'simpanan'])" submit-label="Simpan perubahan" />
            @endunless
        </form>
    </div>
</x-layouts.portal>
