<x-layouts.portal :role="$role" title="Edit Teller — {{ $teller->nama_lengkap }}">
    <div class="mx-auto max-w-4xl">
        <x-page-header
            icon="pencil-square"
            eyebrow="{{ $teller->username }} · {{ ucfirst($teller->status) }}"
            :title="'Edit '.$teller->nama_lengkap"
            description="Perbarui identitas, status akun, atau ganti kata sandi."
            :back-href="route('portal.tellers.index', ['role' => $role])"
            back-label="Daftar teller"
        />

        <form method="POST" action="{{ route('portal.tellers.update', ['role' => $role, 'teller' => $teller]) }}" class="mt-6 space-y-5">
            @csrf
            @method('PUT')
            <x-validation-summary />
            <x-tellers.form :teller="$teller" :editing="true" />
            <x-form-actions class="mt-6" note="Kosongkan kata sandi bila tidak ingin mengubahnya." :cancel-href="route('portal.tellers.index', ['role' => $role])" submit-label="Simpan perubahan" />
        </form>
    </div>
</x-layouts.portal>
