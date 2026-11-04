<x-layouts.portal :role="$role" title="Detail SHU">
    <div class="mx-auto max-w-7xl space-y-6">
        <x-page-header icon="calculator" eyebrow="SHU" :title="$period->nama_periode" :description="'Status '.$period->status.' · pool Rp'.number_format((float) $period->total_shu, 0, ',', '.')">
            <x-slot:actions>
                <flux:button :href="route('portal.shu.index', ['role' => $role])" variant="ghost">Kembali</flux:button>
                <flux:button :href="route('portal.documents.shu-period', ['role' => $role, 'period' => $period->id])" variant="outline" icon="document">Laporan PDF</flux:button>
                @if($period->status !== 'final')
                    <flux:modal.trigger name="ubah-periode"><flux:button variant="filled">Ubah kebijakan</flux:button></flux:modal.trigger>
                    <form method="POST" action="{{ route('portal.shu.process', ['role' => $role]) }}">@csrf<input type="hidden" name="aksi" value="hitung" /><input type="hidden" name="periode_id" value="{{ $period->id }}" /><flux:button type="submit" variant="primary">Hitung ulang</flux:button></form>
                @endif
                @if($period->status === 'dihitung')
                    <flux:modal.trigger name="sahkan-periode"><flux:button variant="primary">Sahkan</flux:button></flux:modal.trigger>
                @endif
            </x-slot:actions>
        </x-page-header>

        <div class="grid gap-4 lg:grid-cols-3">
            <section class="rounded-2xl border border-slate-200 bg-white p-5 lg:col-span-2">
                <h2 class="text-base font-semibold text-slate-900">Daftar hak anggota</h2>
                <div class="mt-3 max-h-[32rem] space-y-2 overflow-y-auto">
                    @forelse($members as $member)
                        <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-100 px-3 py-2 text-sm">
                            <div>
                                <p class="font-semibold text-slate-800">{{ $member->nama_snapshot }}</p>
                                <p class="text-xs text-slate-500">{{ $member->kode_snapshot }} · Rp{{ number_format((float) $member->total_shu, 0, ',', '.') }} · {{ $member->status_bayar }}</p>
                            </div>
                            @if($period->status === 'final' && $member->status_bayar === 'belum' && (int) $member->total_shu > 0)
                                <flux:modal.trigger name="bayar-{{ $member->id }}"><flux:button size="sm" variant="primary">Bayar</flux:button></flux:modal.trigger>
                            @endif
                        </div>
                        @if($period->status === 'final' && $member->status_bayar === 'belum' && (int) $member->total_shu > 0)
                            <x-confirm-action-modal name="bayar-{{ $member->id }}" title="Bayar SHU anggota?" description="{{ $member->nama_snapshot }} · Rp{{ number_format((float) $member->total_shu, 0, ',', '.') }}" confirm-label="Bayar" :action="route('portal.shu.process', ['role' => $role])">
                                <input type="hidden" name="aksi" value="bayar" />
                                <input type="hidden" name="periode_id" value="{{ $period->id }}" />
                                <input type="hidden" name="shu_anggota_id" value="{{ $member->id }}" />
                                <input type="hidden" name="payment_nonce" value="{{ $paymentNonce }}" />
                                <flux:field><flux:label>Metode</flux:label><flux:select name="metode" required><option value="cash">Cash</option><option value="transfer">Transfer</option></flux:select></flux:field>
                                <flux:field><flux:label>Referensi</flux:label><flux:input name="referensi" required minlength="3" /></flux:field>
                                <x-image-upload name="bukti" label="Bukti" required />
                            </x-confirm-action-modal>
                        @endif
                    @empty
                        <p class="text-sm text-slate-500">Belum ada hasil perhitungan.</p>
                    @endforelse
                </div>
                @if(method_exists($members, 'hasPages') && $members->hasPages())
                    <div class="mt-4"><x-server-pagination :paginator="$members" /></div>
                @endif
            </section>
            <section class="space-y-4">
                <div class="rounded-2xl border border-slate-200 bg-white p-5">
                    <h2 class="text-base font-semibold text-slate-900">Alokasi</h2>
                    <div class="mt-3 space-y-2 text-sm">
                        @forelse($allocation as $key => $value)
                            <div class="flex justify-between gap-3"><span>{{ $categories[$key] ?? $key }}</span><strong>Rp{{ number_format((float) $value, 0, ',', '.') }}</strong></div>
                        @empty
                            <p class="text-slate-500">Hitung periode untuk melihat alokasi.</p>
                        @endforelse
                    </div>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-white p-5">
                    <h2 class="text-base font-semibold text-slate-900">Audit</h2>
                    <div class="mt-3 max-h-64 space-y-2 overflow-y-auto text-sm">
                        @forelse($audit as $row)
                            <div class="rounded-lg border border-slate-100 px-3 py-2">
                                <p class="font-semibold">{{ $row->aksi }} · {{ $row->petugas ?? 'Sistem' }}</p>
                                <p class="text-xs text-slate-500">{{ \Illuminate\Support\Carbon::parse($row->created_at)->translatedFormat('d M Y H:i') }}</p>
                            </div>
                        @empty
                            <p class="text-slate-500">Belum ada audit.</p>
                        @endforelse
                    </div>
                </div>
            </section>
        </div>
    </div>

    @if($period->status !== 'final')
        <x-confirm-action-modal name="ubah-periode" title="Ubah kebijakan periode" description="Hasil perhitungan lama akan dihapus." confirm-label="Simpan" :action="route('portal.shu.process', ['role' => $role])" max-width="2xl">
            <input type="hidden" name="aksi" value="ubah" />
            <input type="hidden" name="periode_id" value="{{ $period->id }}" />
            <div class="grid gap-3 sm:grid-cols-2">
                <flux:field class="sm:col-span-2"><flux:label>Total SHU (Rp)</flux:label><flux:input type="number" name="total_shu" :value="$period->total_shu" min="1" required /></flux:field>
                <flux:field class="sm:col-span-2"><flux:label>Dasar jasa simpanan</flux:label><flux:select name="basis_simpanan" required><option value="rata_harian" @selected(($policy['basis_simpanan'] ?? '') === 'rata_harian')>Rata-rata harian</option><option value="saldo_akhir" @selected(($policy['basis_simpanan'] ?? '') === 'saldo_akhir')>Saldo akhir</option></flux:select></flux:field>
                @foreach($categories as $key => $label)
                    <flux:field><flux:label>{{ $label }} (%)</flux:label><flux:input type="number" name="persen_{{ $key }}" step="0.01" min="0" max="100" :value="isset($policy['persen_bp'][$key]) ? number_format($policy['persen_bp'][$key]/100, 2, '.', '') : 0" required /></flux:field>
                @endforeach
            </div>
        </x-confirm-action-modal>
    @endif

    @if($period->status === 'dihitung')
        <x-confirm-action-modal name="sahkan-periode" title="Sahkan periode SHU?" description="Periode final terkunci dan tidak dapat diubah." confirm-label="Sahkan" :action="route('portal.shu.process', ['role' => $role])">
            <input type="hidden" name="aksi" value="sahkan" />
            <input type="hidden" name="periode_id" value="{{ $period->id }}" />
            <flux:field><flux:label>Referensi RAT</flux:label><flux:input name="referensi_rat" required minlength="5" /></flux:field>
            <label class="flex items-center gap-2 text-sm text-slate-700"><input type="checkbox" name="konfirmasi_rat" value="1" required /> Saya telah memeriksa kontribusi sumber.</label>
        </x-confirm-action-modal>
    @endif
</x-layouts.portal>
