<x-layouts.portal :role="$role" title="Migrasi data">
    <div class="mx-auto max-w-4xl space-y-6">
        <x-page-header icon="arrow-up-tray" eyebrow="Laporan & pengaturan" title="Migrasi data" description="Unduh template berversi, jalankan dry-run, lalu impor idempoten dengan proteksi duplikasi." />

        <section class="rounded-2xl border border-slate-200 bg-white p-5 space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-base font-semibold text-slate-900">Template</h2>
                    <p class="mt-1 text-sm text-slate-500">Versi aktif: <span class="font-mono">{{ $version }}</span></p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <flux:button :href="route('portal.migration.template', ['role' => $role])" variant="primary" icon="arrow-down-tray">Unduh template</flux:button>
                    <flux:button :href="route('portal.migration.export', ['role' => $role])" variant="filled" icon="table-cells">Ekspor data saat ini</flux:button>
                </div>
            </div>
            <form method="POST" action="{{ route('portal.migration.import', ['role' => $role]) }}" enctype="multipart/form-data" class="space-y-4">
                @csrf
                <flux:field><flux:label>File workbook (.xlsx)</flux:label><flux:input type="file" name="workbook" accept=".xlsx,.xls" required /><flux:error name="workbook" /></flux:field>
                <label class="flex items-center gap-2 text-sm text-slate-700"><input type="checkbox" name="dry_run" value="1" checked /> Dry-run (tidak menulis data)</label>
                <x-form-actions submit-label="Proses workbook" note="Impor final hanya setelah dry-run bersih." />
            </form>
        </section>

        @if($lastResult)
            <section class="rounded-2xl border border-slate-200 bg-white p-5">
                <h2 class="text-base font-semibold text-slate-900">Hasil terakhir</h2>
                <p class="mt-1 text-sm text-slate-500">{{ ($lastResult['dry_run'] ?? false) ? 'Dry-run' : 'Impor final' }}</p>
                <div class="mt-4 grid gap-3 sm:grid-cols-2">
                    <x-stat-card label="Nasabah baru" :value="(string) ($lastResult['imported']['nasabah'] ?? 0)" hint="Skipped {{ $lastResult['skipped']['nasabah'] ?? 0 }}" tone="teal" icon="users" />
                    <x-stat-card label="Pinjaman baru" :value="(string) ($lastResult['imported']['pinjaman'] ?? 0)" hint="Skipped {{ $lastResult['skipped']['pinjaman'] ?? 0 }}" tone="sky" icon="banknotes" />
                </div>
                @if(!empty($lastResult['failures']))
                    <div class="mt-4 space-y-2">
                        @foreach($lastResult['failures'] as $failure)
                            <p class="rounded-lg border border-rose-100 bg-rose-50 px-3 py-2 text-sm text-rose-800">{{ $failure['sheet'] }} baris {{ $failure['row'] }}: {{ $failure['message'] }}</p>
                        @endforeach
                    </div>
                @endif
            </section>
        @endif
    </div>
</x-layouts.portal>
