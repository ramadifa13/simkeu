<x-layouts.portal :role="$role" title="Data Teller">
    <div class="mx-auto max-w-7xl">
        <x-page-header
            class="ui-page-header-rich"
            icon="users"
            eyebrow="Akun operasional"
            title="Kelola akun Teller."
            description="Buat, ubah status, reset kata sandi, atau hapus akun Teller. Khusus Admin."
        >
            <x-slot:actions>
                <flux:button :href="route('portal.tellers.create', ['role' => $role])" variant="primary" color="teal" icon="user-plus">
                    Tambah teller
                </flux:button>
            </x-slot:actions>
        </x-page-header>

        <div class="mt-8">
            <livewire:tellers.teller-table :role="$role" />
        </div>
    </div>
</x-layouts.portal>
