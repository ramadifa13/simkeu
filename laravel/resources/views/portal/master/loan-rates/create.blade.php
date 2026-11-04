<x-layouts.portal :role="$role" title="Tambah Tarif Tenor">
    <div class="mx-auto max-w-3xl">
        <x-page-header icon="receipt-percent" eyebrow="Master data" title="Tambah tarif tenor" description="Tentukan bunga khusus untuk tenor tertentu." :back-href="route('portal.master.index', ['role' => $role, 'tab' => 'tarif'])" back-label="Tarif tenor" />
        <form method="POST" action="{{ route('portal.master.loan-rates.store', ['role' => $role]) }}" class="mt-6 space-y-5">
            @csrf
            <x-validation-summary />
            <x-master.loan-rate-form :loans="$loans" />
            <x-form-actions class="mt-6" note="Pastikan jenis pinjaman sudah dibuat terlebih dahulu." :cancel-href="route('portal.master.index', ['role' => $role, 'tab' => 'tarif'])" />
        </form>
    </div>
</x-layouts.portal>
