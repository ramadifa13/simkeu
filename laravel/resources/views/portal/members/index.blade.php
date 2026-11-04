<x-layouts.portal :role="request()->route('role')" title="Data Anggota">
    <div class="mx-auto max-w-7xl">
        <x-page-header class="ui-page-header-rich" icon="users" eyebrow="Data anggota" title="Kelola data anggota." description="Cari, filter, urutkan, dan tinjau data anggota dari basis data legacy.">
            <x-slot:actions><div class="flex flex-wrap gap-2"><flux:button :href="route('portal.members.export', ['role' => request()->route('role')])" variant="outline" icon="arrow-down-tray">Ekspor CSV</flux:button><flux:button :href="route('portal.members.create', ['role' => request()->route('role')])" variant="primary" color="teal">Tambah anggota</flux:button></div></x-slot:actions>
        </x-page-header>
        <div class="mt-8"><livewire:members.member-table :role="request()->route('role')" /></div>
    </div>
</x-layouts.portal>
