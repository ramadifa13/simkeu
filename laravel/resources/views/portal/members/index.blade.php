<x-layouts.portal :role="request()->route('role')" title="Data Anggota">
    <div class="mx-auto max-w-7xl">
        <x-page-header eyebrow="Data anggota" title="Kelola data anggota." description="Cari, filter, urutkan, dan tinjau data anggota dari basis data legacy.">
            <x-slot:actions><flux:button :href="route('portal.members.create', ['role' => request()->route('role')])" variant="primary">Tambah anggota</flux:button></x-slot:actions>
        </x-page-header>
        <div class="mt-8"><livewire:members.member-table :role="request()->route('role')" /></div>
    </div>
</x-layouts.portal>
