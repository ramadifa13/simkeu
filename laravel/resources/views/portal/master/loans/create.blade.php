<x-layouts.portal :role="$role" title="Tambah Jenis Pinjaman">
    <div class="mx-auto max-w-4xl">
        <x-page-header icon="banknotes" eyebrow="Master data" title="Tambah jenis pinjaman" description="Atur plafond, tenor, frekuensi, dan kebutuhan jaminan." :back-href="route('portal.master.index', ['role' => $role, 'tab' => 'pinjaman'])" back-label="Jenis pinjaman" />
        <form method="POST" action="{{ route('portal.master.loans.store', ['role' => $role]) }}" class="mt-6 space-y-5">
            @csrf
            <x-validation-summary />
            <x-master.loan-form />
            <x-form-actions class="mt-6" note="Tarif tenor spesifik dapat ditambahkan setelah produk tersimpan." :cancel-href="route('portal.master.index', ['role' => $role, 'tab' => 'pinjaman'])" />
        </form>
    </div>
</x-layouts.portal>
