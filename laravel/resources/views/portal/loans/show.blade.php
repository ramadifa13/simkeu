@php
    $focus = request('focus');
    $workflowActive = match ($focus) {
        'pelunasan' => 'settlement',
        'denda' => 'penalties',
        default => $loan->status === 'lunas' ? 'settlement' : 'installments',
    };
    $nextUnpaid = $installments->first(fn ($row) => $row->status !== 'lunas');
    $nextUnpaidDue = $nextUnpaid
        ? \Carbon\Carbon::parse($nextUnpaid->tanggal_jatuh_tempo)->translatedFormat('d M Y')
        : null;
@endphp
<x-layouts.portal :role="$role" title="Detail pinjaman">
    <div class="mx-auto max-w-7xl space-y-6">
        <x-page-header icon="banknotes" eyebrow="{{ $loan->kode_pinjaman }}" title="Pinjaman {{ $loan->nama_lengkap }}" description="{{ $loan->nama_jenis }} · {{ $loan->tenor }} bulan · {{ str_replace('_', ' ', $loan->frekuensi) }}" :back-href="route('portal.loans.index', ['role' => $role])">
            <x-slot:actions>
                <x-status-badge :status="$loan->status" />
                @if ($agreement ?? null)
                    <flux:button :href="route('portal.loans.agreement', ['role' => $role, 'loan' => $loan->id])" variant="outline" icon="document-text">
                        Perjanjian
                    </flux:button>
                    <flux:button :href="route('portal.documents.agreement', ['role' => $role, 'loan' => $loan->id])" variant="outline" icon="document-arrow-down">
                        PDF perjanjian
                    </flux:button>
                @endif
                <flux:button :href="route('portal.documents.disbursement', ['role' => $role, 'loan' => $loan->id])" variant="outline" icon="banknotes">
                    Slip PDF
                </flux:button>
                <flux:button :href="route('portal.documents.installment-book', ['role' => $role, 'loan' => $loan->id])" variant="outline" icon="book-open">
                    Buku PDF
                </flux:button>
                @if ($role !== 'anggota' && ($cartCount ?? 0) > 0)
                    <flux:button :href="route('portal.loans.cart', ['role' => $role, 'from' => $loan->id])" variant="primary" icon="shopping-cart">
                        Keranjang ({{ $cartCount }})
                    </flux:button>
                @endif
            </x-slot:actions>
        </x-page-header>
        <x-loans.workflow :role="$role" :active="$workflowActive" />

        @if ($needsDisbursementPhoto ?? false)
            <x-loans.next-step
                eyebrow="Dokumen pencairan"
                title="Lengkapi foto pencairan"
                description="Data migrasi atau pinjaman tanpa foto nasabah. Unggah foto nasabah saat menerima uang; foto jaminan opsional untuk gadai."
                tone="amber"
            >
                <x-slot:actions>
                    <flux:modal.trigger name="complete-disbursement-photos">
                        <flux:button variant="primary" icon="camera">Lengkapi foto</flux:button>
                    </flux:modal.trigger>
                </x-slot:actions>
            </x-loans.next-step>
            <x-confirm-action-modal
                name="complete-disbursement-photos"
                title="Lengkapi foto pencairan"
                description="Foto nasabah wajib. Untuk pinjaman gadai, foto barang dan foto nasabah bersama barang bersifat opsional."
                confirm-label="Simpan foto"
                max-width="xl"
                :action="route('portal.loans.disbursement.photos', ['role' => $role, 'loan' => $loan->id])"
            >
                <x-loans.disbursement-photos-fields
                    :requires-collateral="(bool) $loan->memerlukan_jaminan"
                    :collateral-optional="true"
                />
            </x-confirm-action-modal>
        @endif

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-stat-card label="Plafon" :value="'Rp '.number_format($loan->jumlah_pinjaman, 0, ',', '.')" icon="banknotes" />
            <x-stat-card label="Sisa pokok" :value="'Rp '.number_format($loan->sisa_pokok, 0, ',', '.')" icon="wallet" />
            <x-stat-card label="Total tagihan" :value="'Rp '.number_format($loan->total_pinjaman, 0, ',', '.')" icon="receipt-refund" />
            <x-stat-card label="Jatuh tempo akhir" :value="\Carbon\Carbon::parse($loan->jatuh_tempo)->translatedFormat('d M Y')" icon="calendar-days" />
        </div>

        @if ($loan->status === 'aktif' && $role !== 'anggota' && $nextUnpaid && $focus !== 'pelunasan')
            <x-loans.next-step
                :title="'Bayar angsuran ke-'.$nextUnpaid->angsuran_ke"
                :description="'Jatuh tempo '.$nextUnpaidDue.($nextUnpaid->denda > $nextUnpaid->denda_dibayar ? ' · ada sisa denda yang harus dilunasi lebih dulu.' : '.')"
                :tone="$nextUnpaid->status === 'telat' ? 'rose' : 'teal'"
            >
                <x-slot:actions>
                    @if ($nextUnpaid->denda > $nextUnpaid->denda_dibayar)
                        <flux:modal.trigger :name="'penalty-'.$nextUnpaid->id"><flux:button variant="outline" icon="exclamation-triangle">Bayar denda</flux:button></flux:modal.trigger>
                    @endif
                    <flux:modal.trigger :name="'pay-'.$nextUnpaid->id"><flux:button variant="primary" icon="banknotes">Bayar angsuran</flux:button></flux:modal.trigger>
                </x-slot:actions>
            </x-loans.next-step>
        @endif

        @if ($loan->status === 'aktif' && $role !== 'anggota')
            <x-loans.next-step
                eyebrow="Pelunasan"
                :title="'Total pelunasan saat ini: Rp '.number_format($settlement['total'], 0, ',', '.')"
                :description="$settlement['penalty'] > 0
                    ? 'Sisa denda Rp '.number_format($settlement['penalty'], 0, ',', '.').' harus dibayar terlebih dahulu.'
                    : ($settlement['discount_amount'] > 0
                        ? 'Diskon bunga persetujuan Admin: Rp '.number_format($settlement['discount_amount'], 0, ',', '.').' ('.number_format($settlement['discount_percent'], 2, ',', '.').'%).'
                        : 'Belum ada diskon pelunasan yang disetujui Admin.')"
                :tone="$focus === 'pelunasan' ? 'teal' : 'slate'"
            >
                <x-slot:actions>
                    <flux:modal.trigger name="settle-loan"><flux:button variant="primary" icon="check-badge">Proses pelunasan</flux:button></flux:modal.trigger>
                    @if ($settlement['request_status'] !== 'menunggu' && $settlement['request_status'] !== 'disetujui')
                        <flux:modal.trigger name="request-settlement"><flux:button variant="outline">Ajukan diskon</flux:button></flux:modal.trigger>
                    @endif
                    @if ($role === 'admin' && $settlement['request_status'] === 'menunggu')
                        <flux:modal.trigger name="review-settlement"><flux:button variant="outline">Tinjau pengajuan</flux:button></flux:modal.trigger>
                    @endif
                </x-slot:actions>
            </x-loans.next-step>
            <x-confirm-action-modal
                name="request-settlement"
                title="Ajukan pelunasan dipercepat"
                description="Admin akan meninjau diskon bunga sebelum pelunasan diproses."
                confirm-label="Kirim pengajuan"
                :action="route('portal.loans.settlement.request', ['role' => $role, 'loan' => $loan->id])"
            >
                <x-loans.note-field name="catatan" placeholder="Alasan atau catatan (opsional)" />
            </x-confirm-action-modal>
            @if ($role === 'admin' && $settlement['request_status'] === 'menunggu')
                <x-confirm-action-modal
                    name="review-settlement"
                    title="Tinjau pelunasan dipercepat"
                    description="Diskon hanya berlaku pada sisa bunga dan dicatat pada audit."
                    confirm-label="Simpan keputusan"
                    :action="route('portal.loans.settlement.review', ['role' => $role, 'loan' => $loan->id])"
                >
                    <x-loans.early-settlement-review-fields />
                </x-confirm-action-modal>
            @endif
            <x-confirm-action-modal
                name="settle-loan"
                title="Konfirmasi pelunasan"
                :description="'Total yang dicatat: Rp '.number_format($settlement['total'], 0, ',', '.').'. Semua angsuran tersisa akan ditandai lunas.'"
                confirm-label="Lunaskan pinjaman"
                max-width="xl"
                :action="route('portal.loans.settle', ['role' => $role, 'loan' => $loan->id])"
            >
                <x-loans.settle-fields
                    :loan="$loan"
                    :collateral="$collateral"
                    :early-approved="$settlement['request_status'] === 'disetujui'"
                />
            </x-confirm-action-modal>
        @endif

        @if ($loan->status === 'lunas' && $role !== 'anggota' && $collateral && $collateral->status_jaminan === 'dititipkan')
            @php
                $returnStatus = $collateralRequest->status ?? null;
            @endphp
            @if (! $returnStatus || $returnStatus === 'ditolak')
                <x-loans.next-step
                    eyebrow="Jaminan"
                    title="Ajukan pengembalian jaminan"
                    description="Upload bukti jaminan dan bukti lunas, lalu tunggu keputusan Admin sebelum serah terima BAST."
                    tone="amber"
                >
                    <x-slot:actions>
                        <flux:modal.trigger name="request-collateral"><flux:button variant="primary" icon="archive-box">Ajukan pengembalian</flux:button></flux:modal.trigger>
                    </x-slot:actions>
                </x-loans.next-step>
                <x-confirm-action-modal
                    name="request-collateral"
                    title="Ajukan pengembalian jaminan"
                    description="Admin akan meninjau kelengkapan bukti sebelum serah terima."
                    confirm-label="Kirim pengajuan"
                    max-width="xl"
                    :action="route('portal.loans.collateral.request', ['role' => $role, 'loan' => $loan->id])"
                >
                    <x-loans.request-collateral-fields />
                </x-confirm-action-modal>
            @elseif (in_array($returnStatus, ['menunggu', 'pengecekan'], true) && $role === 'admin')
                <x-loans.next-step
                    eyebrow="Jaminan"
                    :title="$returnStatus === 'pengecekan' ? 'Pengajuan sedang dicek' : 'Pengajuan pengembalian menunggu keputusan'"
                    :description="$collateralRequest->alasan ?? 'Tandai pengecekan dulu, lalu setujui atau tolak sebelum serah terima.'"
                    tone="amber"
                >
                    <x-slot:actions>
                        <flux:modal.trigger name="review-collateral"><flux:button variant="primary" icon="clipboard-document-check">Tinjau pengajuan</flux:button></flux:modal.trigger>
                    </x-slot:actions>
                </x-loans.next-step>
                <x-confirm-action-modal
                    name="review-collateral"
                    title="Tinjau pengembalian jaminan"
                    description="Gunakan pengecekan untuk menandai pemeriksaan Admin, lalu setujui atau tolak."
                    confirm-label="Simpan keputusan"
                    :action="route('portal.loans.collateral.review', ['role' => $role, 'loan' => $loan->id])"
                >
                    <x-loans.review-collateral-fields :return-status="$returnStatus" />
                </x-confirm-action-modal>
            @elseif ($returnStatus === 'disetujui')
                <x-loans.next-step
                    eyebrow="Jaminan"
                    title="Jaminan siap diserahkan"
                    description="Catat foto kondisi dan tanda tangan kedua pihak sebagai BAST."
                    tone="amber"
                >
                    <x-slot:actions>
                        <flux:modal.trigger name="return-collateral"><flux:button variant="primary" icon="archive-box-arrow-down">Serahkan jaminan</flux:button></flux:modal.trigger>
                    </x-slot:actions>
                </x-loans.next-step>
                <x-confirm-action-modal
                    name="return-collateral"
                    title="Serah terima jaminan"
                    description="Pastikan barang telah diperiksa bersama nasabah sebelum menyimpan BAST."
                    confirm-label="Simpan BAST"
                    max-width="xl"
                    :action="route('portal.loans.collateral.return', ['role' => $role, 'loan' => $loan->id])"
                >
                    <x-loans.return-collateral-fields />
                </x-confirm-action-modal>
            @elseif (in_array($returnStatus, ['menunggu', 'pengecekan'], true))
                <x-loans.next-step
                    eyebrow="Jaminan"
                    title="Menunggu keputusan Admin"
                    description="Pengajuan pengembalian sudah dikirim. Serah terima baru dapat dilakukan setelah disetujui."
                    tone="slate"
                />
            @elseif ($returnStatus === 'diserahkan')
                <x-loans.next-step
                    eyebrow="Jaminan"
                    title="BAST sudah dicatat"
                    description="Unduh kwitansi BAST PDF untuk arsip serah terima jaminan."
                >
                    <x-slot:actions>
                        <flux:button :href="route('portal.documents.bast', ['role' => $role, 'loan' => $loan->id])" variant="outline" icon="document-arrow-down">BAST PDF</flux:button>
                    </x-slot:actions>
                </x-loans.next-step>
            @endif
        @endif
        @if ($loan->status === 'lunas')
            <div class="flex flex-wrap justify-end gap-2">
                <flux:button :href="route('portal.loans.settlement.receipt', ['role' => $role, 'loan' => $loan->id])" variant="outline" icon="document-check">Bukti lunas</flux:button>
                @if ($role === 'admin')
                    <flux:modal.trigger name="reverse-settlement"><flux:button variant="outline">Batalkan pelunasan</flux:button></flux:modal.trigger>
                    <x-confirm-action-modal
                        name="reverse-settlement"
                        title="Batalkan pelunasan"
                        description="Sistem membuat mutasi kas pembalik dan mengaktifkan kembali jadwal yang dilunasi."
                        confirm-label="Buat pembatalan"
                        variant="danger"
                        :action="route('portal.loans.settlement.reverse', ['role' => $role, 'loan' => $loan->id])"
                    >
                        <x-loans.note-field
                            name="alasan"
                            placeholder="Alasan pembatalan (minimal 5 karakter)"
                            :required="true"
                            :minlength="5"
                        />
                    </x-confirm-action-modal>
                @endif
            </div>
        @endif

        @php
            $selectableIds = $role !== 'anggota'
                ? $installments->filter(fn ($row) => $row->status !== 'lunas')->pluck('id')->map(fn ($id) => (int) $id)->values()->all()
                : [];
        @endphp
        <div
            class="space-y-3"
            x-data="{
                selected: [],
                selectable: @js($selectableIds),
                toggleAll() {
                    this.selected = this.selected.length === this.selectable.length ? [] : [...this.selectable];
                },
                isChecked(id) { return this.selected.includes(Number(id)); },
                toggle(id) {
                    id = Number(id);
                    this.selected = this.isChecked(id) ? this.selected.filter((row) => row !== id) : [...this.selected, id];
                }
            }"
        >
        @if ($role !== 'anggota' && count($selectableIds))
            <div class="loan-select-bar" x-show="selected.length" x-cloak>
                <div>
                    <p class="text-sm font-bold text-slate-900"><span x-text="selected.length"></span> angsuran dipilih</p>
                    <p class="text-xs text-slate-600">Tambahkan sekaligus ke keranjang, lalu bayar dalam satu transaksi.</p>
                </div>
                <form method="POST" action="{{ route('portal.loans.installments.cart.bulk', ['role' => $role, 'loan' => $loan->id]) }}" class="flex flex-wrap gap-2">
                    @csrf
                    <template x-for="id in selected" :key="id">
                        <input type="hidden" name="installment_ids[]" :value="id">
                    </template>
                    <x-loans.action-chip type="button" tone="ghost" @click="selected = []">Batal pilih</x-loans.action-chip>
                    <x-loans.action-chip type="submit" tone="primary" icon="shopping-cart" x-bind:disabled="selected.length === 0">Keranjang terpilih</x-loans.action-chip>
                </form>
            </div>
        @endif

        <x-data-table
            title="Jadwal angsuran"
            icon="calendar-days"
            :count="$installments->count()"
            :sortable="false"
            :columns="$role !== 'anggota'
                ? ['pick' => 'Pilih', 'ke' => 'Ke', 'jatuh_tempo' => 'Jatuh tempo', 'tagihan' => 'Pokok + bunga', 'denda' => 'Denda', 'status' => 'Status', 'actions' => 'Aksi']
                : ['ke' => 'Ke', 'jatuh_tempo' => 'Jatuh tempo', 'tagihan' => 'Pokok + bunga', 'denda' => 'Denda', 'status' => 'Status', 'actions' => 'Aksi']"
            :mobile-hidden="['tagihan', 'denda']"
        >
            @if ($role !== 'anggota')
                <x-slot:tableActions>
                    <x-loans.action-chip type="button" icon="queue-list" @click="toggleAll()" x-text="selected.length === selectable.length && selectable.length ? 'Batal semua' : 'Pilih semua belum lunas'"></x-loans.action-chip>
                </x-slot:tableActions>
            @endif
            @foreach ($installments as $installment)
                @php
                    $inCart = in_array((int) $installment->id, $cartIds ?? [], true);
                    $reversalRequest = $reversalRequests->get($installment->id);
                    $canSelect = $role !== 'anggota' && $installment->status !== 'lunas';
                @endphp
                <tr @class(['bg-emerald-50/60' => $inCart, 'bg-sky-50/70' => false]) :class="{ 'bg-sky-50/80': isChecked({{ (int) $installment->id }}) && !{{ $inCart ? 'true' : 'false' }} }">
                    @if ($role !== 'anggota')
                        <td class="px-5 py-3">
                            @if ($canSelect)
                                <input
                                    type="checkbox"
                                    class="rounded border-slate-300 text-teal-700 focus:ring-teal-600"
                                    :checked="isChecked({{ (int) $installment->id }})"
                                    @change="toggle({{ (int) $installment->id }})"
                                    aria-label="Pilih angsuran ke-{{ $installment->angsuran_ke }}"
                                >
                            @endif
                        </td>
                    @endif
                    <td class="px-5 py-3 font-semibold">
                        <span class="inline-flex items-center gap-2">
                            {{ $installment->angsuran_ke }}
                            @if ($inCart)
                                <span class="loan-in-cart-badge">Di keranjang</span>
                            @endif
                        </span>
                    </td>
                    <td class="px-5 py-3">{{ \Carbon\Carbon::parse($installment->tanggal_jatuh_tempo)->translatedFormat('d M Y') }}@if($installment->keterlambatan_hari)<span class="block text-xs text-rose-600">{{ $installment->keterlambatan_hari }} hari terlambat</span>@endif</td>
                    <td class="hidden px-5 py-3 font-medium md:table-cell"><x-money :amount="$installment->jumlah_angsuran" /></td>
                    <td class="hidden px-5 py-3 md:table-cell"><x-money :amount="$installment->denda" />@if($installment->denda_dibayar > 0)<span class="block text-xs text-emerald-700">Dibayar: Rp {{ number_format($installment->denda_dibayar, 0, ',', '.') }}</span>@endif</td>
                    <td class="px-5 py-3"><x-status-badge :status="$installment->status" /></td>
                    <td class="loan-actions-cell px-5 py-3 text-right">
                        <div class="loan-installment-actions">
                            @if ($installment->status === 'lunas')
                                <x-loans.action-chip
                                    :href="route('portal.loans.receipt', ['role' => $role, 'loan' => $loan->id, 'installment' => $installment->id])"
                                    icon="document-text"
                                >
                                    Bukti
                                </x-loans.action-chip>
                                @if ($role === 'admin' && $installment->cara_bayar !== 'pelunasan')
                                    @if ($reversalRequest && $reversalRequest->status === 'menunggu')
                                        <flux:modal.trigger name="review-reversal-{{ $reversalRequest->id }}">
                                            <x-loans.action-chip type="button" icon="clipboard-document-check">Tinjau</x-loans.action-chip>
                                        </flux:modal.trigger>
                                        <x-confirm-action-modal
                                            :name="'review-reversal-'.$reversalRequest->id"
                                            title="Tinjau pengajuan pembatalan"
                                            :description="'Teller mengajukan: '.$reversalRequest->alasan"
                                            confirm-label="Simpan keputusan"
                                            :action="route('portal.loans.installments.reversal.review', ['role' => $role, 'loan' => $loan->id, 'reversalRequest' => $reversalRequest->id])"
                                        >
                                            <x-loans.review-decision-fields
                                                :options="[
                                                    'disetujui' => 'Setujui dan buat mutasi pembalik',
                                                    'ditolak' => 'Tolak pengajuan',
                                                ]"
                                            />
                                        </x-confirm-action-modal>
                                    @else
                                        <flux:modal.trigger name="reverse-{{ $installment->id }}">
                                            <x-loans.action-chip type="button" tone="danger" icon="arrow-uturn-left">Batalkan</x-loans.action-chip>
                                        </flux:modal.trigger>
                                        <x-confirm-action-modal
                                            :name="'reverse-'.$installment->id"
                                            title="Batalkan pembayaran angsuran"
                                            description="Sistem membuat mutasi kas pembalik; pembayaran awal tidak dihapus."
                                            confirm-label="Buat pembatalan"
                                            variant="danger"
                                            :action="route('portal.loans.installments.reverse', ['role' => $role, 'loan' => $loan->id, 'installment' => $installment->id])"
                                        >
                                            <x-loans.note-field
                                                name="alasan"
                                                placeholder="Alasan pembatalan (minimal 5 karakter)"
                                                :required="true"
                                                :minlength="5"
                                            />
                                        </x-confirm-action-modal>
                                    @endif
                                @elseif ($role === 'teller' && $installment->cara_bayar !== 'pelunasan')
                                    @if ($reversalRequest && $reversalRequest->status === 'menunggu')
                                        <span class="loan-action-chip is-muted" title="Menunggu keputusan Admin">Menunggu</span>
                                    @elseif ($installment->tanggal_bayar && \Carbon\Carbon::parse($installment->tanggal_bayar)->isToday())
                                        <flux:modal.trigger name="request-reversal-{{ $installment->id }}">
                                            <x-loans.action-chip type="button" tone="danger" icon="arrow-uturn-left">Ajukan batal</x-loans.action-chip>
                                        </flux:modal.trigger>
                                        <x-confirm-action-modal
                                            :name="'request-reversal-'.$installment->id"
                                            title="Ajukan pembatalan pembayaran"
                                            description="Pembayaran tidak berubah sampai Admin menyetujui pengajuan ini."
                                            confirm-label="Kirim pengajuan"
                                            :action="route('portal.loans.installments.reversal.request', ['role' => $role, 'loan' => $loan->id, 'installment' => $installment->id])"
                                        >
                                            <x-loans.note-field
                                                name="alasan"
                                                placeholder="Alasan pembatalan (minimal 5 karakter)"
                                                :required="true"
                                                :minlength="5"
                                            />
                                        </x-confirm-action-modal>
                                    @endif
                                @endif
                            @elseif ($role !== 'anggota')
                                @if ($installment->denda > $installment->denda_dibayar)
                                    <flux:modal.trigger name="penalty-{{ $installment->id }}">
                                        <x-loans.action-chip type="button" icon="exclamation-triangle">Denda</x-loans.action-chip>
                                    </flux:modal.trigger>
                                    <x-confirm-action-modal
                                        :name="'penalty-'.$installment->id"
                                        title="Catat pembayaran denda"
                                        :description="'Sisa denda: Rp '.number_format($installment->denda - $installment->denda_dibayar, 0, ',', '.')"
                                        confirm-label="Simpan denda"
                                        max-width="xl"
                                        :action="route('portal.loans.installments.penalty.pay', ['role' => $role, 'loan' => $loan->id, 'installment' => $installment->id])"
                                    >
                                        <x-loans.pay-penalty-fields :installment="$installment" />
                                    </x-confirm-action-modal>
                                @endif
                                <flux:modal.trigger name="pay-{{ $installment->id }}">
                                    <x-loans.action-chip type="button" tone="primary" icon="banknotes">Bayar</x-loans.action-chip>
                                </flux:modal.trigger>
                                <x-loans.cart-toggle
                                    :role="$role"
                                    :loan-id="$loan->id"
                                    :installment-id="$installment->id"
                                    :in-cart="$inCart"
                                />
                                <x-confirm-action-modal
                                    :name="'pay-'.$installment->id"
                                    title="Catat pembayaran angsuran"
                                    :description="'Angsuran ke-'.$installment->angsuran_ke.' akan dibayar dan masuk ke buku kas.'"
                                    confirm-label="Simpan pembayaran"
                                    max-width="xl"
                                    :action="route('portal.loans.installments.pay', ['role' => $role, 'loan' => $loan->id, 'installment' => $installment->id])"
                                >
                                    <x-loans.pay-installment-fields :role="$role" :installment="$installment" />
                                </x-confirm-action-modal>
                            @else
                                <span class="text-xs text-slate-400">—</span>
                            @endif
                        </div>
                    </td>
                </tr>
            @endforeach
        </x-data-table>
        </div>

        <x-loans.cart-bar
            :role="$role"
            :count="$cartCount ?? 0"
            :labels="$cartLabels ?? []"
            :loan-id="$loan->id"
            :on-this-loan-count="$cartOnThisLoanCount ?? 0"
        />
    </div>
</x-layouts.portal>
