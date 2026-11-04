<x-layouts.portal :role="$role" title="Pencairan pinjaman">
    <div class="mx-auto max-w-5xl space-y-6">
        <x-page-header icon="banknotes" eyebrow="{{ $application->kode_pengajuan }}" title="Pencairan pinjaman" description="{{ $application->nama_lengkap }} · {{ $application->nama_jenis }}" :back-href="route('portal.loans.applications.index', ['role' => $role])" />
        <x-loans.workflow :role="$role" active="disbursement" />
        <section class="grid gap-3 sm:grid-cols-3"><x-stat-card label="Anggota" :value="$application->nama_lengkap" icon="user" /><x-stat-card label="Dana dicairkan" :value="'Rp '.number_format($application->jumlah_pinjaman, 0, ',', '.')" icon="banknotes" /><x-stat-card label="Jadwal" :value="$application->tenor.' bulan · '.str_replace('_', ' ', $application->frekuensi)" icon="calendar-days" /></section>
        <form method="POST" enctype="multipart/form-data" action="{{ route('portal.loans.applications.disburse', ['role' => $role, 'application' => $application->id]) }}" class="space-y-5">
            @csrf
            <x-form-section number="1" title="Tanggal dan saksi" description="Tanggal tidak boleh sebelum pengajuan atau melebihi hari ini.">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-loans.payment-date-field
                        name="tanggal_pencairan"
                        label="Tanggal pencairan"
                        :min="substr($application->tanggal_pengajuan, 0, 10)"
                    />
                    <flux:field>
                        <flux:label>Cicilan dimuka <span class="text-xs font-normal text-slate-500">(hanya tanggal lampau)</span></flux:label>
                        <flux:input type="number" name="sudah_dibayar" min="0" value="0" />
                    </flux:field>
                    <flux:field>
                        <flux:label>Nama saksi 1 <x-required-mark /></flux:label>
                        <flux:input name="nama_saksi_1" required />
                    </flux:field>
                    <flux:field>
                        <flux:label>Nama saksi 2 <x-required-mark /></flux:label>
                        <flux:input name="nama_saksi_2" required />
                    </flux:field>
                </div>
            </x-form-section>
            <x-form-section number="2" title="Dokumentasi pencairan" description="JPG, PNG, atau WebP; maksimal 1 MB per gambar. Disimpan privat.">
                <x-loans.disbursement-photos-fields
                    :requires-collateral="(bool) $application->memerlukan_jaminan"
                    :collateral-optional="false"
                    @class([
                        'grid gap-4 space-y-0!',
                        'md:grid-cols-3' => $application->memerlukan_jaminan,
                        'md:grid-cols-1' => ! $application->memerlukan_jaminan,
                    ])
                />
            </x-form-section>
            <x-form-section number="3" title="Perjanjian dan tanda tangan" description="Tanda tangan nasabah, Teller, dan kedua saksi wajib sebelum pencairan. Tanda tangan Admin memakai pengaturan privat yang aktif.">
                <div class="grid gap-5 md:grid-cols-2"><x-signature-field label="Tanda tangan nasabah" name="ttd_nasabah" /><x-signature-field label="Tanda tangan Teller" name="ttd_teller" /><x-signature-field label="Tanda tangan saksi 1" name="ttd_saksi_1" /><x-signature-field label="Tanda tangan saksi 2" name="ttd_saksi_2" /></div>
                <div class="mt-5">
                    <x-image-upload
                        label="Lampiran perjanjian (opsional, maksimal 10 file)"
                        name="lampiran[]"
                        :multiple="true"
                        description="JPG, PNG, atau WebP. Setiap file dikompres otomatis maksimal 1 MB dan disimpan privat."
                    />
                </div>
            </x-form-section>
            <x-form-actions :cancel-href="route('portal.loans.applications.index', ['role' => $role])" submit-label="Proses pencairan" />
        </form>
    </div>
</x-layouts.portal>
