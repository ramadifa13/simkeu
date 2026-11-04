<x-layouts.portal :role="$role" title="Edit Tarif Denda">
    <div class="mx-auto max-w-3xl">
        <x-page-header icon="pencil-square" eyebrow="Master data" title="Edit tarif denda" description="Perbarui batas nominal atau denda per hari." :back-href="route('portal.master.index', ['role' => $role, 'tab' => 'denda'])" back-label="Tarif denda" />
        <form method="POST" action="{{ route('portal.master.penalty-rates.update', ['role' => $role, 'rate' => $rate]) }}" class="mt-6 space-y-5">
            @csrf @method('PUT')
            <x-validation-summary />
            <x-master.penalty-rate-form :rate="$rate" :editing="true" />
            <x-form-actions class="mt-6" note="Perubahan master tidak menghitung ulang denda yang sudah tercatat." :cancel-href="route('portal.master.index', ['role' => $role, 'tab' => 'denda'])" submit-label="Simpan perubahan" />
        </form>
    </div>
</x-layouts.portal>
