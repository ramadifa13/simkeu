<x-layouts.portal :role="$role" title="Ubah Anggota — {{ $member->nama_lengkap }}">
    <div class="mx-auto max-w-4xl">
        <x-page-header icon="pencil-square" eyebrow="Data anggota" :title="'Ubah '.$member->nama_lengkap" description="Kode identitas tetap; perubahan dokumen hanya mengganti file pada jenis yang sama." :back-href="route('portal.members.show', ['role' => $role, 'member' => $member])" back-label="Detail anggota" />
        <form method="POST" action="{{ route('portal.members.update', ['role' => $role, 'member' => $member]) }}" enctype="multipart/form-data" class="mt-6 space-y-5" data-member-form data-member-code="{{ $member->kode_anggota }}">@csrf @method('PUT')
            <x-members.form :member="$member" :group-leaders="$groupLeaders" editing />
            <x-form-actions class="mt-6" note="Perubahan langsung memperbarui profil dan status akun anggota." :cancel-href="route('portal.members.show', ['role' => $role, 'member' => $member])" submit-label="Simpan perubahan" />
        </form>
    </div>
</x-layouts.portal>
