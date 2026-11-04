<x-layouts.portal :role="$role" title="Tambah Tarif Denda">
    <div class="mx-auto max-w-3xl">
        <x-page-header icon="exclamation-triangle" eyebrow="Master data" title="Tambah tarif denda" description="Tetapkan batas nominal dan denda harian." :back-href="route('portal.master.index', ['role' => $role, 'tab' => 'denda'])" back-label="Tarif denda" />
        <form method="POST" action="{{ route('portal.master.penalty-rates.store', ['role' => $role]) }}" class="mt-6 space-y-5">
            @csrf
            <x-validation-summary />
            <x-master.penalty-rate-form />
            <x-form-actions class="mt-6" note="Kalkulasi denda angsuran diterapkan pada workflow Fase 7." :cancel-href="route('portal.master.index', ['role' => $role, 'tab' => 'denda'])" />
        </form>
    </div>
</x-layouts.portal>
