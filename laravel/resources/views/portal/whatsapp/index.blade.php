<x-layouts.portal :role="$role" title="Pengingat WhatsApp">
    @php($activeFilter = collect($filters)->except('per_page')->filter(fn ($value) => filled($value))->isNotEmpty())
    <div class="mx-auto max-w-7xl space-y-6">
        <x-page-header
            icon="chat-bubble-left-right"
            eyebrow="Notifikasi"
            title="Pengingat jatuh tempo WhatsApp"
            description="Log pengiriman harian dari perintah wa:remind-due (jadwal 08:00). Khusus Admin."
        />

        @unless($available)
            <div class="rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-900">
                Tabel <code>wa_pengingat_log</code> belum tersedia. Jalankan migrasi/skema legacy atau perintah <code>wa:remind-due</code> sekali agar tabel dibuat.
            </div>
        @else
            <x-data-table
                title="Log pengingat"
                :count="$logs->total()"
                :sortable="false"
                :columns="['waktu' => 'Jadwal', 'anggota' => 'Anggota', 'pinjaman' => 'Pinjaman', 'nomor' => 'Nomor', 'status' => 'Status', 'detail' => 'Detail']"
                :mobile-hidden="['pinjaman', 'detail']"
            >
                <x-slot:filters>
                    <form method="GET" class="grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4 lg:items-end">
                        <flux:field class="lg:col-span-2"><flux:label>Pencarian</flux:label><flux:input type="search" name="q" :value="$filters['q'] ?? ''" placeholder="Nomor, anggota, atau kode pinjaman" /></flux:field>
                        <flux:field>
                            <flux:label>Status</flux:label>
                            <flux:select name="status">
                                <option value="">Semua</option>
                                @foreach (['terkirim', 'gagal', 'dilewati', 'antrian'] as $status)
                                    <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ ucfirst($status) }}</option>
                                @endforeach
                            </flux:select>
                        </flux:field>
                        <div class="flex gap-2">
                            <flux:button type="submit" variant="primary" class="flex-1">Terapkan</flux:button>
                            @if($activeFilter)
                                <flux:button :href="route('portal.whatsapp.index', ['role' => $role])" variant="ghost">Reset</flux:button>
                            @endif
                        </div>
                    </form>
                </x-slot:filters>

                @forelse($logs as $row)
                    <tr class="hover:bg-slate-50/80">
                        <td class="whitespace-nowrap px-5 py-3 text-sm text-slate-700">
                            {{ \Illuminate\Support\Carbon::parse($row->tanggal_jadwal)->translatedFormat('d M Y') }}
                            @if($row->sent_at)
                                <p class="mt-1 text-xs text-slate-500">{{ \Illuminate\Support\Carbon::parse($row->sent_at)->translatedFormat('d M Y H:i') }}</p>
                            @endif
                        </td>
                        <td class="px-5 py-3 font-semibold text-slate-900">
                            {{ $row->nama_lengkap ?? '—' }}
                            <p class="mt-1 font-mono text-xs font-normal text-slate-500">{{ $row->kode_anggota ?? '' }}</p>
                        </td>
                        <td class="hidden px-5 py-3 font-mono text-xs text-slate-600 md:table-cell">
                            {{ $row->kode_pinjaman ?? '—' }}
                            @if($row->angsuran_ke)
                                <span class="text-slate-400">· ke-{{ $row->angsuran_ke }}</span>
                            @endif
                        </td>
                        <td class="whitespace-nowrap px-5 py-3 font-mono text-xs text-slate-700">{{ $row->nomor }}</td>
                        <td class="px-5 py-3"><x-status-badge :status="$row->status" /></td>
                        <td class="hidden px-5 py-3 text-xs text-slate-500 md:table-cell">
                            Percobaan {{ $row->percobaan }}
                            @if($row->error_message)
                                <p class="mt-1 text-rose-600">{{ \Illuminate\Support\Str::limit($row->error_message, 80) }}</p>
                            @elseif($row->message_id)
                                <p class="mt-1 font-mono">{{ \Illuminate\Support\Str::limit($row->message_id, 24) }}</p>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="p-4"><x-empty-state title="Belum ada log" description="Pengingat akan muncul setelah jadwal harian berjalan." icon="chat-bubble-left-right" /></td></tr>
                @endforelse

                <x-slot:footer>
                    <x-server-pagination :paginator="$logs" :query="collect(request()->query())->except(['page', 'per_page'])->all()" />
                </x-slot:footer>
            </x-data-table>
        @endunless
    </div>
</x-layouts.portal>
