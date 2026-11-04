@php
    $koperasi = $snapshot['koperasi'] ?? [];
    $nasabah = $snapshot['nasabah'] ?? [];
    $pinjaman = $snapshot['pinjaman'] ?? [];
    $jadwal = $snapshot['jadwal_angsuran'] ?? [];
    $jaminan = $snapshot['jaminan'] ?? [];
    $penandatangan = $snapshot['penandatangan'] ?? [];
@endphp
<x-layouts.portal :role="$role" title="Pratinjau perjanjian pinjaman">
    <div class="mx-auto max-w-5xl space-y-5">
        <x-page-header
            icon="document-text"
            eyebrow="{{ $agreement->nomor_perjanjian }}"
            title="Pratinjau perjanjian"
            description="Snapshot perjanjian yang disimpan saat pencairan. Hanya baca; cetak PDF masuk Fase 8."
            :back-href="route('portal.loans.show', ['role' => $role, 'loan' => $loan->id])"
        >
            <x-slot:actions>
                <x-status-badge :status="$loan->status" />
            </x-slot:actions>
        </x-page-header>

        <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <x-stat-card label="Versi" :value="ucfirst((string) $agreement->versi)" icon="document" />
            <x-stat-card label="Ditandatangani" :value="$agreement->ditandatangani_at ? \Carbon\Carbon::parse($agreement->ditandatangani_at)->translatedFormat('d M Y, H:i') : '—'" icon="calendar-days" />
            <x-stat-card label="Plafon" :value="'Rp '.number_format((float) ($pinjaman['jumlah_pinjaman'] ?? $loan->jumlah_pinjaman), 0, ',', '.')" icon="banknotes" />
            <x-stat-card label="Tenor" :value="($pinjaman['tenor'] ?? $loan->tenor).' bulan'" icon="clock" />
        </section>

        <x-form-section title="Para pihak" description="Identitas koperasi dan nasabah pada snapshot.">
            <dl class="grid gap-4 sm:grid-cols-2">
                <div>
                    <dt class="text-xs font-medium text-slate-500">Pihak pertama</dt>
                    <dd class="mt-1 text-sm font-semibold text-slate-900">{{ $koperasi['nama'] ?? ($koperasi['nama_koperasi'] ?? '—') }}</dd>
                    <dd class="mt-1 text-sm text-slate-600">{{ $penandatangan['admin'] ?? '—' }}@if(!empty($penandatangan['jabatan_admin'])) · {{ $penandatangan['jabatan_admin'] }}@endif</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium text-slate-500">Nasabah</dt>
                    <dd class="mt-1 text-sm font-semibold text-slate-900">{{ $nasabah['nama'] ?? $loan->nama_lengkap }}</dd>
                    <dd class="mt-1 text-sm text-slate-600">{{ $nasabah['kode'] ?? $loan->kode_anggota }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium text-slate-500">Saksi 1</dt>
                    <dd class="mt-1 text-sm font-semibold text-slate-900">{{ $penandatangan['saksi_1'] ?? $agreement->nama_saksi_1 ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium text-slate-500">Saksi 2</dt>
                    <dd class="mt-1 text-sm font-semibold text-slate-900">{{ $penandatangan['saksi_2'] ?? $agreement->nama_saksi_2 ?? '—' }}</dd>
                </div>
            </dl>
        </x-form-section>

        <x-form-section title="Ketentuan pinjaman" description="Ringkasan plafon, bunga, dan jadwal dari snapshot.">
            <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div><dt class="text-xs text-slate-500">Produk</dt><dd class="mt-1 text-sm font-medium">{{ $pinjaman['produk'] ?? $loan->nama_jenis }}</dd></div>
                <div><dt class="text-xs text-slate-500">Frekuensi</dt><dd class="mt-1 text-sm font-medium">{{ str_replace('_', ' ', (string) ($pinjaman['frekuensi'] ?? $loan->frekuensi)) }}</dd></div>
                <div><dt class="text-xs text-slate-500">Total tagihan</dt><dd class="mt-1 text-sm font-medium"><x-money :amount="$pinjaman['total_pinjaman'] ?? $loan->total_pinjaman" /></dd></div>
            </dl>

            @if (! empty($jaminan['ada']))
                <div class="mt-4 rounded-xl border border-amber-100 bg-amber-50/70 p-4 text-sm text-amber-950">
                    <p class="font-semibold">Jaminan</p>
                    <p class="mt-1">{{ $jaminan['jenis'] ?? '—' }}</p>
                    <p class="mt-1 text-amber-900/80">{{ $jaminan['detail'] ?? '' }}</p>
                </div>
            @endif

            @if (is_array($jadwal) && count($jadwal))
                <div class="mt-5 overflow-x-auto rounded-xl border border-slate-200">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-4 py-2 text-left">Ke</th>
                                <th class="px-4 py-2 text-left">Jatuh tempo</th>
                                <th class="px-4 py-2 text-right">Angsuran</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($jadwal as $row)
                                <tr>
                                    <td class="px-4 py-2">{{ $row['angsuran_ke'] ?? $row['ke'] ?? '—' }}</td>
                                    <td class="px-4 py-2">{{ isset($row['tanggal_jatuh_tempo']) ? \Carbon\Carbon::parse($row['tanggal_jatuh_tempo'])->translatedFormat('d M Y') : ($row['jatuh_tempo'] ?? '—') }}</td>
                                    <td class="px-4 py-2 text-right"><x-money :amount="$row['jumlah_angsuran'] ?? $row['nominal'] ?? 0" /></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-form-section>

        @if (! blank($snapshot['isi_perjanjian'] ?? null))
            <x-form-section title="Isi perjanjian" description="Teks perjanjian yang di-snapshot saat pencairan.">
                <div class="prose prose-sm max-w-none whitespace-pre-line text-slate-700">{{ $snapshot['isi_perjanjian'] }}</div>
            </x-form-section>
        @endif

        @if ($attachments->isNotEmpty())
            <x-form-section title="Lampiran" description="File tambahan yang dilampirkan pada perjanjian.">
                <ul class="space-y-2 text-sm">
                    @foreach ($attachments as $attachment)
                        <li class="rounded-lg border border-slate-200 px-3 py-2 text-slate-700">Lampiran {{ $attachment->urutan }} · {{ basename((string) $attachment->file_path) }}</li>
                    @endforeach
                </ul>
            </x-form-section>
        @endif
    </div>
</x-layouts.portal>
