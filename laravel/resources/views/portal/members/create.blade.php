<x-layouts.portal :role="$role" title="Tambah Anggota">
    <div class="mx-auto max-w-4xl">
        <x-page-header icon="user-plus" eyebrow="Data anggota" title="Tambah anggota baru" description="Lengkapi identitas, klasifikasi, dan dokumen anggota." :back-href="route('portal.members.index', ['role' => $role])" back-label="Daftar anggota" />
        <form method="POST" action="{{ route('portal.members.store', ['role' => $role]) }}" enctype="multipart/form-data" class="mt-6 space-y-5" data-member-form>@csrf
            <x-members.form :group-leaders="$groupLeaders" />
            <x-form-actions class="mt-6" note="Pastikan data dan dokumen sudah benar sebelum disimpan." :cancel-href="route('portal.members.index', ['role' => $role])" submit-label="Simpan anggota" />
        </form>
    </div>
</x-layouts.portal>
