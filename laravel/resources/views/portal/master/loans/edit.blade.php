<x-layouts.portal :role="$role" title="Edit {{ $loan->nama_jenis }}">
    <div class="mx-auto max-w-4xl">
        <x-page-header icon="pencil-square" eyebrow="{{ $loan->kode_jenis }}" :title="'Edit '.$loan->nama_jenis" description="Perbarui plafond, tenor, frekuensi, atau status produk." :back-href="route('portal.master.index', ['role' => $role, 'tab' => 'pinjaman'])" back-label="Jenis pinjaman" />
        <form method="POST" action="{{ route('portal.master.loans.update', ['role' => $role, 'loan' => $loan]) }}" class="mt-6 space-y-5">
            @csrf @method('PUT')
            <x-validation-summary />
            <x-master.loan-form :loan="$loan" :editing="true" />
            <x-form-actions class="mt-6" note="Perubahan berlaku untuk pengajuan baru." :cancel-href="route('portal.master.index', ['role' => $role, 'tab' => 'pinjaman'])" submit-label="Simpan perubahan" />
        </form>
    </div>
</x-layouts.portal>
