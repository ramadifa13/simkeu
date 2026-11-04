<x-layouts.portal :role="$role" title="Edit Tarif Tenor">
    <div class="mx-auto max-w-3xl">
        <x-page-header icon="pencil-square" eyebrow="Master data" title="Edit tarif tenor" description="Perbarui jenis pinjaman, tenor, atau bunga." :back-href="route('portal.master.index', ['role' => $role, 'tab' => 'tarif'])" back-label="Tarif tenor" />
        <form method="POST" action="{{ route('portal.master.loan-rates.update', ['role' => $role, 'rate' => $rate]) }}" class="mt-6 space-y-5">
            @csrf @method('PUT')
            <x-validation-summary />
            <x-master.loan-rate-form :rate="$rate" :loans="$loans" :editing="true" />
            <x-form-actions class="mt-6" note="Perubahan berlaku untuk pengajuan baru." :cancel-href="route('portal.master.index', ['role' => $role, 'tab' => 'tarif'])" submit-label="Simpan perubahan" />
        </form>
    </div>
</x-layouts.portal>
