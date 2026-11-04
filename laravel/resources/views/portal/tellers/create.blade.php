<x-layouts.portal :role="$role" title="Tambah Teller">
    <div class="mx-auto max-w-4xl">
        <x-page-header
            icon="user-plus"
            eyebrow="Akun operasional"
            title="Tambah teller baru"
            description="Lengkapi identitas dan kata sandi awal untuk akun operasional."
            :back-href="route('portal.tellers.index', ['role' => $role])"
            back-label="Daftar teller"
        />

        <form method="POST" action="{{ route('portal.tellers.store', ['role' => $role]) }}" class="mt-6 space-y-5">
            @csrf
            <x-validation-summary />
            <x-tellers.form />
            <x-form-actions class="mt-6" note="Username harus unik. Status nonaktif menolak akses masuk." :cancel-href="route('portal.tellers.index', ['role' => $role])" submit-label="Simpan teller" />
        </form>
    </div>
</x-layouts.portal>
