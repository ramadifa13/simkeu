<x-layouts.portal :role="request()->route('role')" title="Data Anggota">
    <div class="mx-auto max-w-7xl">
        <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
            <div><p class="text-sm font-semibold uppercase tracking-[0.2em] text-teal-700">Data anggota</p><h1 class="mt-2 text-3xl font-semibold tracking-tight">Kelola data anggota.</h1><p class="mt-3 text-slate-600">Cari, filter, urutkan, dan tinjau data anggota dari basis data legacy.</p></div>
            <flux:button :href="route('portal.members.create', ['role' => request()->route('role')])" variant="primary">Tambah anggota</flux:button>
        </div>
        <div class="mt-8"><livewire:members.member-table :role="request()->route('role')" /></div>
    </div>
</x-layouts.portal>
