<x-layouts.portal :role="$role" title="Tambah Jenis Simpanan">
    <div class="mx-auto max-w-4xl">
        <x-page-header icon="wallet" eyebrow="Master data" title="Tambah jenis simpanan" description="Produk simpanan baru untuk rekening anggota." :back-href="route('portal.master.index', ['role' => $role, 'tab' => 'simpanan'])" back-label="Jenis simpanan" />
        <form method="POST" action="{{ route('portal.master.savings.store', ['role' => $role]) }}" class="mt-6 space-y-5">
            @csrf
            <x-validation-summary />
            <x-master.saving-form />
            <x-form-actions class="mt-6" note="Kode ANG, BONUS, dan SHU tidak dapat dipakai." :cancel-href="route('portal.master.index', ['role' => $role, 'tab' => 'simpanan'])" />
        </form>
    </div>
</x-layouts.portal>
