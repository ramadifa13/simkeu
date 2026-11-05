@php
    $workflowActive = match (true) {
        $loan !== null => 'installments',
        $application->status === 'menunggu' => 'review',
        $application->status === 'disetujui' => 'disbursement',
        default => 'applications',
    };
@endphp
<x-layouts.portal :role="$role" title="Detail pengajuan pinjaman">
    <div class="mx-auto max-w-5xl space-y-5">
        <x-page-header icon="document-text" eyebrow="Pengajuan pinjaman" :title="$application->kode_pengajuan" description="Tinjau identitas, ketentuan, jaminan, dan riwayat keputusan pengajuan." :back-href="route('portal.loans.applications.index', ['role' => $role])">
            <x-slot:actions>
                <div class="flex flex-wrap gap-2">
                    @if (in_array($application->status, ['disetujui', 'dicairkan'], true))
                        <flux:button :href="route('portal.documents.draft-agreement', ['role' => $role, 'application' => $application->id])" target="_blank" variant="outline" icon="printer">Cetak draft</flux:button>
                    @endif
                    @if ($application->status === 'disetujui' && ! $loan && in_array($role, ['admin', 'teller'], true))
                        <flux:button :href="route('portal.loans.applications.disburse.form', ['role' => $role, 'application' => $application->id])" variant="primary" icon="banknotes">Proses pencairan</flux:button>
                    @elseif ($loan)
                        <flux:button :href="route('portal.loans.show', ['role' => $role, 'loan' => $loan->id])" variant="primary" icon="arrow-top-right-on-square">Buka pinjaman</flux:button>
                    @endif
                    @if ($role === 'admin' && $application->status === 'menunggu')
                        <flux:modal.trigger name="review-loan-application"><flux:button variant="primary" icon="clipboard-document-check">Tinjau pengajuan</flux:button></flux:modal.trigger>
                    @elseif ($role === 'admin' && $application->status === 'disetujui' && ! $loan)
                        <flux:modal.trigger name="cancel-approved-application"><flux:button variant="danger" icon="x-circle">Batalkan pencairan</flux:button></flux:modal.trigger>
                    @endif
                </div>
            </x-slot:actions>
        </x-page-header>
        <x-loans.workflow :role="$role" :active="$workflowActive" />

        @if ($role === 'admin' && $application->status === 'menunggu')
            <x-loans.next-step
                title="Tinjau dan putuskan pengajuan ini"
                description="Periksa identitas, tenor, dan jaminan sebelum menyetujui atau menolak."
                tone="amber"
            >
                <x-slot:actions>
                    <flux:modal.trigger name="review-loan-application"><flux:button variant="primary" icon="clipboard-document-check">Tinjau pengajuan</flux:button></flux:modal.trigger>
                </x-slot:actions>
            </x-loans.next-step>
        @elseif ($application->status === 'disetujui' && ! $loan && in_array($role, ['admin', 'teller'], true))
            <x-loans.next-step
                title="Pengajuan siap dicairkan"
                description="{{ ($delayRequest?->status ?? null) === 'menunggu' ? 'Ada pengajuan tunda cair menunggu keputusan Admin.' : (($delayRequest?->status ?? null) === 'disetujui' ? 'TC disetujui; pencairan diblokir hingga status dibersihkan.' : 'Lengkapi tanggal pencairan, saksi, foto, dan tanda tangan — atau ajukan tunda cair bila nasabah belum siap.') }}"
            >
                <x-slot:actions>
                    @if(! $delayRequest)
                        <flux:modal.trigger name="ajukan-tunda-cair"><flux:button variant="filled" icon="pause-circle">Ajukan tunda cair</flux:button></flux:modal.trigger>
                    @endif
                    @if(($delayRequest?->status ?? null) !== 'menunggu' && ($delayRequest?->status ?? null) !== 'disetujui')
                        <flux:button :href="route('portal.loans.applications.disburse.form', ['role' => $role, 'application' => $application->id])" variant="primary" icon="banknotes">Proses pencairan</flux:button>
                    @elseif(($delayRequest?->status ?? null) === 'ditolak')
                        <flux:button :href="route('portal.loans.applications.disburse.form', ['role' => $role, 'application' => $application->id])" variant="primary" icon="banknotes">Proses pencairan</flux:button>
                    @endif
                </x-slot:actions>
            </x-loans.next-step>
        @elseif ($loan)
            <x-loans.next-step
                title="Pinjaman sudah aktif"
                :description="'Lanjut ke jadwal angsuran untuk '.$loan->kode_pinjaman.'.'"
            >
                <x-slot:actions>
                    <flux:button :href="route('portal.loans.show', ['role' => $role, 'loan' => $loan->id])" variant="primary" icon="arrow-top-right-on-square">Buka pinjaman</flux:button>
                </x-slot:actions>
            </x-loans.next-step>
        @endif

        <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-stat-card label="Nominal diajukan" :value="'Rp '.number_format($application->jumlah_pinjaman, 0, ',', '.')" icon="banknotes" />
            <x-stat-card label="Produk" :value="$application->nama_jenis" icon="squares-2x2" />
            <x-stat-card label="Tenor" :value="$application->tenor.' bulan'" icon="calendar-days" />
            <x-stat-card label="Status" :value="ucfirst(str_replace('_', ' ', $application->status))" icon="clipboard-document-check" />
        </section>

        <div class="grid gap-5 lg:grid-cols-[minmax(0,1.2fr)_minmax(18rem,.8fr)]">
            <x-form-section title="Informasi pengajuan" description="Data yang diajukan anggota dan ketentuan cicilan.">
                @php
                    $memberCode = $application->memerlukan_jaminan && filled($application->kode_anggota_jaminan ?? null)
                        ? $application->kode_anggota_jaminan
                        : $application->kode_anggota;
                @endphp
                <dl class="grid gap-x-6 gap-y-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs font-medium text-slate-500">Anggota</dt>
                        <dd class="mt-1 text-sm font-semibold text-slate-900">{{ $application->nama_lengkap }}</dd>
                        <p class="text-xs text-slate-500">{{ $memberCode }}{{ $application->telepon ? ' · '.$application->telepon : '' }}</p>
                    </div>
                    <div>
                        <dt class="text-xs font-medium text-slate-500">Kode pengajuan</dt>
                        <dd class="mt-1 font-mono text-sm text-slate-900">{{ $application->kode_pengajuan }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium text-slate-500">Diajukan oleh</dt>
                        <dd class="mt-1 text-sm text-slate-800">{{ $application->nama_pengaju ?: '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium text-slate-500">Tanggal diajukan</dt>
                        <dd class="mt-1 text-sm text-slate-800">{{ \Carbon\Carbon::parse($application->tanggal_pengajuan)->translatedFormat('d F Y') }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium text-slate-500">Frekuensi angsuran</dt>
                        <dd class="mt-1 text-sm text-slate-800">{{ ucfirst(str_replace('_', ' ', $application->frekuensi)) }}</dd>
                    </div>
                    @if ($application->hari_angsuran)
                        <div>
                            <dt class="text-xs font-medium text-slate-500">Hari angsuran</dt>
                            <dd class="mt-1 text-sm text-slate-800">{{ [1=>'Senin',2=>'Selasa',3=>'Rabu',4=>'Kamis',5=>'Jumat',6=>'Sabtu',7=>'Minggu'][$application->hari_angsuran] ?? '—' }}</dd>
                        </div>
                    @endif
                    <div>
                        <dt class="text-xs font-medium text-slate-500">Skema bunga</dt>
                        <dd class="mt-1 text-sm text-slate-800">{{ ucfirst(str_replace('_', ' ', $application->skema_bunga)) }} · {{ number_format($application->bunga, 2, ',', '.') }}%</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium text-slate-500">Peran SHU</dt>
                        <dd class="mt-1 text-sm text-slate-800">{{ ucfirst(str_replace('_', ' ', $application->kategori_shu)) }}</dd>
                    </div>
                    @if ($application->nama_ketua_kelompok)
                        <div>
                            <dt class="text-xs font-medium text-slate-500">Ketua yang mengajak</dt>
                            <dd class="mt-1 text-sm text-slate-800">{{ $application->nama_ketua_kelompok }}</dd>
                        </div>
                    @endif
                    @if ($application->fasilitas_prioritas)
                        <div>
                            <dt class="text-xs font-medium text-slate-500">Fasilitas</dt>
                            <dd class="mt-1 text-sm font-semibold text-amber-800">Prioritas</dd>
                        </div>
                    @endif
                    @if ($application->nama_admin)
                        <div>
                            <dt class="text-xs font-medium text-slate-500">Diputuskan oleh</dt>
                            <dd class="mt-1 text-sm text-slate-800">{{ $application->nama_admin }}</dd>
                        </div>
                    @endif
                    <div class="sm:col-span-2">
                        <dt class="text-xs font-medium text-slate-500">Tujuan pinjaman</dt>
                        <dd class="mt-1 whitespace-pre-line text-sm leading-6 text-slate-800">{{ $application->tujuan_pinjaman ?: 'Tidak dicantumkan.' }}</dd>
                    </div>
                    @if ($application->catatan_admin)
                        <div class="sm:col-span-2">
                            <dt class="text-xs font-medium text-slate-500">Catatan keputusan Admin</dt>
                            <dd class="mt-1 whitespace-pre-line rounded-xl bg-slate-50 p-3 text-sm leading-6 text-slate-700">{{ $application->catatan_admin }}</dd>
                        </div>
                    @endif
                </dl>
            </x-form-section>

            <div class="space-y-5">
                <x-form-section title="Status proses" description="Tahap berikutnya mengikuti keputusan dan status pencairan.">
                    <div class="flex items-center gap-3"><x-status-badge :status="$application->status" /><p class="text-sm leading-5 text-slate-600">@if($application->status === 'menunggu')Menunggu keputusan Admin.@elseif($application->status === 'disetujui' && ! $loan)Disetujui dan siap dicairkan.@elseif($loan)Pinjaman sudah dibuat dengan kode {{ $loan->kode_pinjaman }}.@else{{ ucfirst(str_replace('_', ' ', $application->status)) }}.@endif</p></div>
                    @if ($application->tanggal_persetujuan)<p class="mt-3 text-xs text-slate-500">Diputuskan {{ \Carbon\Carbon::parse($application->tanggal_persetujuan)->translatedFormat('d M Y, H:i') }}</p>@endif
                </x-form-section>

                @if ($application->memerlukan_jaminan)
                    <x-form-section title="Jaminan" description="Barang yang menjadi jaminan pengajuan ini.">
                        @if ($collateral)
                            <dl class="grid gap-3 text-sm"><div><dt class="text-xs text-slate-500">Jenis barang</dt><dd class="mt-1 font-medium text-slate-900">{{ $collateral->jenis_barang }}</dd></div><div><dt class="text-xs text-slate-500">Identitas barang</dt><dd class="mt-1 text-slate-800">{{ $collateral->identitas_barang }}</dd></div><div><dt class="text-xs text-slate-500">Nilai taksiran</dt><dd class="mt-1 font-semibold text-slate-900"><x-money :amount="$collateral->nilai_taksiran" /></dd></div>@if($collateral->deskripsi_barang)<div><dt class="text-xs text-slate-500">Deskripsi</dt><dd class="mt-1 leading-5 text-slate-700">{{ $collateral->deskripsi_barang }}</dd></div>@endif</dl>
                        @else
                            <x-empty-state title="Data jaminan belum tersedia" description="Periksa kembali data jaminan pengajuan." icon="shield-exclamation" />
                        @endif
                    </x-form-section>
                @endif
            </div>
        </div>

        @if ($role === 'admin' && $application->status === 'menunggu')
            <x-confirm-action-modal
                name="review-loan-application"
                title="Keputusan pengajuan pinjaman"
                description="Pastikan nominal, tujuan, dan ketentuan sudah diperiksa sebelum memutuskan."
                confirm-label="Simpan keputusan"
                :action="route('portal.loans.applications.review', ['role' => $role, 'application' => $application->id])"
            >
                <x-loans.review-decision-fields
                    note-name="catatan_admin"
                    note-placeholder="Catatan untuk anggota (opsional)"
                    :options="[
                        'disetujui' => 'Setujui pengajuan',
                        'ditolak' => 'Tolak pengajuan',
                    ]"
                />
            </x-confirm-action-modal>
        @endif
        @if ($role === 'admin' && $application->status === 'disetujui' && ! $loan)
            <x-confirm-action-modal
                name="cancel-approved-application"
                title="Batalkan proses pencairan"
                description="Pengajuan yang disetujui akan ditandai batal cair. Catatan pembatalan wajib disimpan."
                confirm-label="Batalkan pencairan"
                variant="danger"
                :action="route('portal.loans.applications.cancel', ['role' => $role, 'application' => $application->id])"
            >
                <x-loans.note-field
                    name="catatan_admin"
                    label="Alasan pembatalan"
                    placeholder="Alasan pembatalan pencairan"
                    :required="true"
                    :minlength="5"
                />
            </x-confirm-action-modal>
        @endif
        @if ($application->status === 'disetujui' && ! $loan && ! $delayRequest && in_array($role, ['admin', 'teller'], true))
            <x-confirm-action-modal
                name="ajukan-tunda-cair"
                title="Ajukan tunda cair?"
                description="Pengajuan menunggu keputusan Admin. Persetujuan TC membatalkan pencairan."
                confirm-label="Kirim TC"
                :action="route('portal.layanan.tunda-cair.request', ['role' => $role])"
            >
                <input type="hidden" name="pengajuan_id" value="{{ $application->id }}" />
                <x-loans.note-field
                    name="alasan"
                    label="Alasan tunda cair"
                    placeholder="Contoh: nasabah belum siap menerima dana"
                    :required="true"
                    :minlength="5"
                    :maxlength="2000"
                />
            </x-confirm-action-modal>
        @endif
    </div>
</x-layouts.portal>
