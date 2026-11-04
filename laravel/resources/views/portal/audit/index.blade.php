<x-layouts.portal :role="$role" title="Audit Aktivitas">
    <div class="mx-auto max-w-7xl">
        <x-page-header
            class="ui-page-header-rich"
            icon="clipboard-document-list"
            eyebrow="Keamanan"
            title="Audit aktivitas."
            description="Jejak mutasi dari menu Laravel. Baca-saja, khusus Admin, tanpa membuka isi dokumen privat."
        />

        <div class="mt-6">
            <x-data-table
                class="ui-audit-table"
                title="Riwayat aktivitas"
                :count="$logs->total()"
                :sortable="false"
                :columns="['waktu' => 'Waktu', 'pengguna' => 'Pengguna', 'aksi' => 'Aksi', 'objek' => 'Objek', 'konteks' => 'Konteks', 'ip' => 'IP']"
                :mobile-hidden="['objek', 'konteks', 'ip']"
            >
                <x-slot:filters>
                    <form method="GET" class="ui-table-filter-bar">
                        <x-table-filters>
                            <div class="ui-filter-field grow">
                                <p class="ui-filter-label">Pencarian</p>
                                <flux:input name="search" type="search" :value="$search" placeholder="Cari aksi, objek, atau pengguna" aria-label="Cari audit" />
                            </div>
                            <div class="ui-filter-field">
                                <p class="ui-filter-label">Filter aksi</p>
                                <flux:select name="action" aria-label="Filter aksi audit">
                                    <option value="">Semua aksi</option>
                                    @foreach ($actions as $item)
                                        <option value="{{ $item }}" @selected($action === $item)>{{ $item }}</option>
                                    @endforeach
                                </flux:select>
                            </div>
                            <div class="flex flex-wrap items-end gap-2">
                                <flux:button type="submit" variant="primary" icon="funnel">Terapkan</flux:button>
                                @if ($search !== '' || $action !== '')
                                    <flux:button :href="route('portal.audit.index', ['role' => $role])" variant="ghost">Reset</flux:button>
                                @endif
                            </div>
                        </x-table-filters>
                    </form>
                </x-slot:filters>

                <x-slot:footer>
                    <x-server-pagination :paginator="$logs" :query="['search' => $search, 'action' => $action]" />
                </x-slot:footer>

                @forelse ($logs as $log)
                    <tr class="hover:bg-slate-50/80">
                        <td class="whitespace-nowrap px-5 py-3 text-slate-600">
                            {{ \Illuminate\Support\Carbon::parse($log->created_at)->translatedFormat('d M Y H:i') }}
                        </td>
                        <td class="px-5 py-3 font-medium text-slate-800">
                            {{ $log->actor ?? 'Sistem' }}
                            <p class="mt-1 text-xs font-normal text-slate-500 md:hidden">{{ $log->action }}</p>
                        </td>
                        <td class="px-5 py-3">
                            <span class="ui-audit-action">{{ str_replace(['.', '_'], [' · ', ' '], $log->action) }}</span>
                        </td>
                        <td class="hidden px-5 py-3 text-slate-600 md:table-cell">
                            {{ class_basename($log->auditable_type) }}{{ $log->auditable_id ? ' #'.$log->auditable_id : '' }}
                        </td>
                        <td class="hidden max-w-xs truncate px-5 py-3 text-slate-500 md:table-cell" title="{{ json_encode($log->context, JSON_UNESCAPED_UNICODE) }}">
                            {{ json_encode($log->context, JSON_UNESCAPED_UNICODE) ?: '—' }}
                        </td>
                        <td class="hidden px-5 py-3 font-mono text-xs text-slate-500 md:table-cell">{{ $log->ip_address ?? '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="p-4">
                            <x-empty-state title="Belum ada aktivitas" description="Aktivitas dari modul yang sudah dimigrasikan akan dicatat di sini." />
                        </td>
                    </tr>
                @endforelse
            </x-data-table>
        </div>
    </div>
</x-layouts.portal>
