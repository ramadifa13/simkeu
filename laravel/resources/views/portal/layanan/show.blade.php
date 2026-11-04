<x-layouts.portal :role="$role" title="Detail layanan">
    <div class="mx-auto max-w-7xl space-y-6">
        <x-page-header icon="lifebuoy" eyebrow="Layanan pinjaman" :title="$loan->kode_pinjaman" :description="$loan->nama_lengkap.' · '.$loan->kode_anggota.' · '.$loan->nama_jenis">
            <x-slot:actions>
                <flux:button :href="route('portal.layanan.index', ['role' => $role])" variant="ghost">Kembali</flux:button>
                <flux:button :href="route('portal.loans.show', ['role' => $role, 'loan' => $loan->id])" variant="filled">Lihat pinjaman</flux:button>
            </x-slot:actions>
        </x-page-header>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-stat-card label="Status" :value="ucfirst($loan->status)" hint="Pinjaman" tone="sky" icon="clipboard-document-check" />
            <x-stat-card label="Sisa hutang layanan" :value="'Rp'.number_format((float) $remaining['total'], 0, ',', '.')" hint="Pokok + bunga + denda − kredit" tone="amber" icon="banknotes" />
            <x-stat-card label="Angsuran terbuka" :value="(string) $remaining['jumlah']" hint="Belum lunas" tone="rose" icon="calendar-days" />
            <x-stat-card label="Pemutihan" :value="$pemutihan ? 'Aktif' : 'Nonaktif'" hint="Pengaturan layanan" tone="cyan" icon="receipt-percent" />
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <section class="space-y-3 rounded-2xl border border-slate-200 bg-white p-5">
                <h2 class="text-base font-semibold text-slate-900">Tindakan cepat</h2>
                <div class="flex flex-wrap gap-2">
                    @if($loan->status !== 'lunas')
                        <flux:modal.trigger name="aksi-lapor-macet"><flux:button size="sm">Lapor macet</flux:button></flux:modal.trigger>
                        <flux:modal.trigger name="aksi-ajukan-hapus-denda"><flux:button size="sm" variant="filled">Ajukan hapus denda</flux:button></flux:modal.trigger>
                    @else
                        <flux:modal.trigger name="aksi-prioritas"><flux:button size="sm">Ajukan prioritas</flux:button></flux:modal.trigger>
                    @endif
                    @can('decide-layanan')
                        @if($loan->status !== 'lunas')
                            <flux:modal.trigger name="aksi-reschedule"><flux:button size="sm" variant="filled">Reschedule</flux:button></flux:modal.trigger>
                            @if($loan->memerlukan_jaminan)
                                <flux:modal.trigger name="aksi-eksekusi"><flux:button size="sm" variant="danger">Eksekusi jaminan</flux:button></flux:modal.trigger>
                                <flux:modal.trigger name="aksi-jual"><flux:button size="sm" variant="danger">Jual jaminan</flux:button></flux:modal.trigger>
                            @endif
                            @if($pemutihan)
                                <flux:modal.trigger name="aksi-diskon-denda"><flux:button size="sm" variant="filled">Diskon denda</flux:button></flux:modal.trigger>
                            @endif
                        @else
                            @if(! $loan->memerlukan_jaminan && ! $bonus)
                                <flux:modal.trigger name="aksi-bonus"><flux:button size="sm" variant="primary">Bayar bonus</flux:button></flux:modal.trigger>
                            @endif
                            <flux:modal.trigger name="aksi-repeat"><flux:button size="sm" variant="filled">Repeat order</flux:button></flux:modal.trigger>
                        @endif
                        @if($auction && (float) $auction->kelebihan > (float) $auction->dikembalikan)
                            <flux:modal.trigger name="aksi-kembali-lebih"><flux:button size="sm">Kembalikan lebih</flux:button></flux:modal.trigger>
                        @endif
                    @endcan
                </div>

                @if($macet->where('status', 'menunggu')->isNotEmpty())
                    <div class="rounded-xl border border-amber-100 bg-amber-50/60 p-3 text-sm text-amber-900">Ada laporan macet menunggu keputusan Admin.</div>
                @endif
                @if($priority)
                    <div class="rounded-xl border border-slate-100 p-3 text-sm text-slate-700">Prioritas: <x-status-badge :status="$priority->status" /></div>
                @endif
                @if($collateral)
                    <div class="rounded-xl border border-slate-100 p-3 text-sm text-slate-700">Jaminan: {{ $collateral->status_jaminan ?? '-' }}</div>
                @endif
            </section>

            <section class="rounded-2xl border border-slate-200 bg-white p-5">
                <h2 class="text-base font-semibold text-slate-900">Angsuran terbuka</h2>
                <div class="mt-3 max-h-72 space-y-2 overflow-y-auto">
                    @forelse($installments->where('status', '!=', 'lunas') as $installment)
                        <div class="flex items-center justify-between rounded-lg border border-slate-100 px-3 py-2 text-sm">
                            <div>
                                <p class="font-semibold text-slate-800">Ke-{{ $installment->angsuran_ke }} · #{{ $installment->id }}</p>
                                <p class="text-xs text-slate-500">JT {{ \Illuminate\Support\Carbon::parse($installment->tanggal_jatuh_tempo)->translatedFormat('d M Y') }} · Denda Rp{{ number_format((float) $installment->denda, 0, ',', '.') }}</p>
                            </div>
                            <x-status-badge :status="$installment->status" />
                        </div>
                    @empty
                        <p class="text-sm text-slate-500">Tidak ada angsuran terbuka.</p>
                    @endforelse
                </div>
            </section>
        </div>

        @can('decide-layanan')
            @if($macet->where('status', 'menunggu')->isNotEmpty() || $writeOffs->where('status', 'menunggu')->isNotEmpty() || $pendingPenaltyPayments->isNotEmpty() || ($priority && in_array($priority->status, ['menunggu', 'pengecekan'], true)))
                <section class="rounded-2xl border border-slate-200 bg-white p-5">
                    <h2 class="text-base font-semibold text-slate-900">Keputusan Admin</h2>
                    <div class="mt-3 flex flex-wrap gap-2">
                        @foreach($macet->where('status', 'menunggu') as $laporan)
                            <flux:modal.trigger name="putusan-macet-{{ $laporan->id }}"><flux:button size="sm">Putuskan macet #{{ $laporan->id }}</flux:button></flux:modal.trigger>
                        @endforeach
                        @foreach($writeOffs->where('status', 'menunggu') as $pengajuan)
                            <flux:modal.trigger name="putusan-hapus-{{ $pengajuan->id }}"><flux:button size="sm" variant="filled">Putuskan hapus denda #{{ $pengajuan->id }}</flux:button></flux:modal.trigger>
                        @endforeach
                        @foreach($pendingPenaltyPayments as $bayar)
                            <flux:modal.trigger name="approve-denda-{{ $bayar->id }}"><flux:button size="sm">Setujui bayar denda #{{ $bayar->id }}</flux:button></flux:modal.trigger>
                        @endforeach
                        @if($priority && in_array($priority->status, ['menunggu', 'pengecekan'], true))
                            <flux:modal.trigger name="approve-prioritas"><flux:button size="sm" variant="primary">Setujui prioritas</flux:button></flux:modal.trigger>
                            <flux:modal.trigger name="koreksi-prioritas"><flux:button size="sm" variant="filled">Koreksi</flux:button></flux:modal.trigger>
                            <flux:modal.trigger name="tolak-prioritas"><flux:button size="sm" variant="danger">Tolak</flux:button></flux:modal.trigger>
                        @endif
                    </div>
                </section>
            @endif
        @endcan

        <section class="rounded-2xl border border-slate-200 bg-white p-5">
            <h2 class="text-base font-semibold text-slate-900">Audit layanan</h2>
            <div class="mt-3 space-y-2">
                @forelse($audit as $row)
                    <div class="rounded-lg border border-slate-100 px-3 py-2 text-sm">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <p class="font-semibold text-slate-800">{{ $row->aksi }} · {{ $row->petugas ?? 'Sistem' }}</p>
                            <p class="text-xs text-slate-500">{{ \Illuminate\Support\Carbon::parse($row->created_at)->translatedFormat('d M Y H:i') }}</p>
                        </div>
                        <p class="mt-1 text-slate-600">{{ $row->detail }}</p>
                    </div>
                @empty
                    <p class="text-sm text-slate-500">Belum ada log layanan.</p>
                @endforelse
            </div>
        </section>
    </div>

    @php($process = route('portal.layanan.process', ['role' => $role, 'loan' => $loan->id]))

    <x-confirm-action-modal name="aksi-lapor-macet" title="Laporkan nasabah macet?" description="Laporan menunggu keputusan Admin." confirm-label="Kirim laporan" :action="$process">
        <input type="hidden" name="aksi" value="lapor_macet" />
        <flux:field><flux:label>Catatan</flux:label><flux:textarea name="catatan" rows="3" required minlength="5" /></flux:field>
    </x-confirm-action-modal>

    <x-confirm-action-modal name="aksi-ajukan-hapus-denda" title="Ajukan hapus denda?" description="Pilih angsuran berdenda." confirm-label="Ajukan" :action="$process">
        <input type="hidden" name="aksi" value="ajukan_hapus_denda" />
        <flux:field><flux:label>Angsuran</flux:label><flux:select name="angsuran_id" required>@foreach($installments->where('status', '!=', 'lunas')->where('denda', '>', 0) as $installment)<option value="{{ $installment->id }}">Ke-{{ $installment->angsuran_ke }} · denda Rp{{ number_format((float) $installment->denda, 0, ',', '.') }}</option>@endforeach</flux:select></flux:field>
        <flux:field><flux:label>Alasan</flux:label><flux:textarea name="catatan" rows="3" required minlength="5" /></flux:field>
    </x-confirm-action-modal>

    <x-confirm-action-modal name="aksi-prioritas" title="Ajukan fasilitas prioritas?" description="Hanya untuk nasabah lunas. Kelayakan: {{ $priorityEligible ? 'memenuhi' : 'belum memenuhi' }}." confirm-label="Ajukan" :action="$process">
        <input type="hidden" name="aksi" value="prioritas" />
        <flux:field><flux:label>Catatan</flux:label><flux:textarea name="catatan" rows="3" required minlength="5" /></flux:field>
    </x-confirm-action-modal>

    @can('decide-layanan')
        <x-confirm-action-modal name="aksi-reschedule" title="Geser jadwal angsuran?" description="Semua angsuran terbuka digeser relatif terhadap jatuh tempo terdekat." confirm-label="Reschedule" :action="$process">
            <input type="hidden" name="aksi" value="reschedule" />
            <flux:field><flux:label>Jatuh tempo baru</flux:label><flux:input type="date" name="tanggal" required /></flux:field>
            <flux:field><flux:label>Alasan</flux:label><flux:textarea name="catatan" rows="3" required minlength="5" /></flux:field>
        </x-confirm-action-modal>

        <x-confirm-action-modal name="aksi-eksekusi" title="Eksekusi jaminan?" description="Status jaminan menjadi dilelang." confirm-label="Eksekusi" variant="danger" :action="$process">
            <input type="hidden" name="aksi" value="eksekusi" />
            <flux:field><flux:label>Catatan</flux:label><flux:textarea name="catatan" rows="3" required minlength="5" /></flux:field>
        </x-confirm-action-modal>

        <x-confirm-action-modal name="aksi-jual" title="Catat penjualan jaminan?" description="Hasil penjualan dialokasikan ke angsuran terbuka." confirm-label="Simpan penjualan" variant="danger" :action="$process" enctype="multipart/form-data">
            <input type="hidden" name="aksi" value="jual_jaminan" />
            <flux:field><flux:label>Harga jual</flux:label><flux:input type="number" name="jumlah" min="1" step="1" required /></flux:field>
            <flux:field><flux:label>Metode</flux:label><flux:select name="metode" required><option value="cash">Cash</option><option value="transfer">Transfer</option></flux:select></flux:field>
            <x-image-upload name="bukti" label="Bukti penjualan" required />
            <flux:field><flux:label>Catatan</flux:label><flux:textarea name="catatan" rows="3" required minlength="5" /></flux:field>
        </x-confirm-action-modal>

        <x-confirm-action-modal name="aksi-diskon-denda" title="Diskon / pemutihan denda?" description="Nilai akhir harus antara yang sudah dibayar dan denda saat ini." confirm-label="Simpan" :action="$process">
            <input type="hidden" name="aksi" value="diskon_denda" />
            <flux:field><flux:label>Angsuran</flux:label><flux:select name="angsuran_id" required>@foreach($installments->where('status', '!=', 'lunas')->where('denda', '>', 0) as $installment)<option value="{{ $installment->id }}">Ke-{{ $installment->angsuran_ke }} · denda Rp{{ number_format((float) $installment->denda, 0, ',', '.') }}</option>@endforeach</flux:select></flux:field>
            <flux:field><flux:label>Denda akhir</flux:label><flux:input type="number" name="jumlah" min="0" step="0.01" required /></flux:field>
            <flux:field><flux:label>Catatan</flux:label><flux:textarea name="catatan" rows="3" required minlength="5" /></flux:field>
        </x-confirm-action-modal>

        <x-confirm-action-modal name="aksi-bonus" title="Bayarkan bonus loyalitas?" description="Nominal harus sesuai perhitungan bunga diterima." confirm-label="Bayar bonus" :action="$process" enctype="multipart/form-data">
            <input type="hidden" name="aksi" value="bayar_bonus" />
            <flux:field><flux:label>Nominal</flux:label><flux:input type="number" name="jumlah" min="1" step="0.01" required /></flux:field>
            <flux:field><flux:label>Metode</flux:label><flux:select name="metode" required><option value="cash">Cash</option><option value="transfer">Transfer</option></flux:select></flux:field>
            <x-image-upload name="bukti" label="Bukti" required />
        </x-confirm-action-modal>

        <x-confirm-action-modal name="aksi-repeat" title="Catat repeat order?" description="Tindak lanjut nasabah lunas." confirm-label="Simpan" :action="$process">
            <input type="hidden" name="aksi" value="repeat_order" />
            <flux:field><flux:label>Catatan</flux:label><flux:textarea name="catatan" rows="3" required minlength="5" /></flux:field>
        </x-confirm-action-modal>

        <x-confirm-action-modal name="aksi-kembali-lebih" title="Kembalikan kelebihan lelang?" description="Mengurangi kas koperasi." confirm-label="Kembalikan" :action="$process" enctype="multipart/form-data">
            <input type="hidden" name="aksi" value="kembalikan_lebih" />
            <x-image-upload name="bukti" label="Bukti pengembalian" required />
        </x-confirm-action-modal>

        @foreach($macet->where('status', 'menunggu') as $laporan)
            <x-confirm-action-modal name="putusan-macet-{{ $laporan->id }}" title="Putuskan laporan macet?" description="{{ $laporan->catatan }}" confirm-label="Simpan keputusan" :action="$process">
                <input type="hidden" name="aksi" value="keputusan_macet" />
                <input type="hidden" name="laporan_id" value="{{ $laporan->id }}" />
                <flux:field><flux:label>Keputusan</flux:label><flux:select name="keputusan" required><option value="ditindaklanjuti">Ditindaklanjuti</option><option value="selesai">Selesai</option><option value="ditolak">Ditolak</option></flux:select></flux:field>
                <flux:field><flux:label>Catatan</flux:label><flux:textarea name="catatan" rows="3" required minlength="5" /></flux:field>
            </x-confirm-action-modal>
        @endforeach

        @foreach($writeOffs->where('status', 'menunggu') as $pengajuan)
            <x-confirm-action-modal name="putusan-hapus-{{ $pengajuan->id }}" title="Putuskan hapus denda?" description="{{ $pengajuan->alasan }}" confirm-label="Simpan" :action="$process">
                <input type="hidden" name="aksi" value="putuskan_hapus_denda" />
                <input type="hidden" name="pengajuan_id" value="{{ $pengajuan->id }}" />
                <flux:field><flux:label>Keputusan</flux:label><flux:select name="keputusan" required><option value="setuju">Setuju</option><option value="tolak">Tolak</option></flux:select></flux:field>
            </x-confirm-action-modal>
        @endforeach

        @foreach($pendingPenaltyPayments as $bayar)
            <x-confirm-action-modal name="approve-denda-{{ $bayar->id }}" title="Setujui pembayaran denda?" description="Nominal Rp{{ number_format((float) $bayar->jumlah, 0, ',', '.') }}" confirm-label="Setujui" :action="$process">
                <input type="hidden" name="aksi" value="approve_denda" />
                <input type="hidden" name="bayar_id" value="{{ $bayar->id }}" />
            </x-confirm-action-modal>
        @endforeach

        <x-confirm-action-modal name="approve-prioritas" title="Setujui prioritas?" description="Kelayakan: {{ $priorityEligible ? 'memenuhi' : 'belum memenuhi' }}." confirm-label="Setujui" :action="$process">
            <input type="hidden" name="aksi" value="approve_prioritas" />
            <flux:field><flux:label>Catatan (opsional)</flux:label><flux:textarea name="catatan" rows="2" /></flux:field>
        </x-confirm-action-modal>
        <x-confirm-action-modal name="koreksi-prioritas" title="Minta koreksi prioritas?" description="Status menjadi pengecekan." confirm-label="Kirim koreksi" :action="$process">
            <input type="hidden" name="aksi" value="koreksi_prioritas" />
            <flux:field><flux:label>Catatan</flux:label><flux:textarea name="catatan" rows="3" required minlength="5" /></flux:field>
        </x-confirm-action-modal>
        <x-confirm-action-modal name="tolak-prioritas" title="Tolak prioritas?" description="Pengajuan ditolak." confirm-label="Tolak" variant="danger" :action="$process">
            <input type="hidden" name="aksi" value="tolak_prioritas" />
            <flux:field><flux:label>Catatan</flux:label><flux:textarea name="catatan" rows="3" required minlength="5" /></flux:field>
        </x-confirm-action-modal>
    @endcan
</x-layouts.portal>
