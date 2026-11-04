@php
    $grandPrincipal = (float) $rows->sum('jumlah_angsuran');
    $backHref = $fromLoan
        ? route('portal.loans.show', ['role' => $role, 'loan' => $fromLoan])
        : route('portal.loans.index', ['role' => $role, 'status' => 'aktif']);
    $grouped = $rows->groupBy('pinjaman_id');
    $blocked = $rows->filter(fn ($row) => ((float) $row->denda - (float) ($row->denda_dibayar ?? 0)) > 0.005);
@endphp
<x-layouts.portal :role="$role" title="Keranjang angsuran">
    <div
        class="loan-cart-page mx-auto max-w-6xl space-y-5"
        x-data="{
            defaultDate: @js(old('default_tanggal_bayar', $defaultDate)),
            defaultMethod: @js(old('default_metode', $defaultMethod)),
            applyDefaults() {
                this.$refs.cartForm.querySelectorAll('[data-cart-date]').forEach((el) => { el.value = this.defaultDate; });
                this.$refs.cartForm.querySelectorAll('[data-cart-method]').forEach((el) => { el.value = this.defaultMethod; });
            }
        }"
    >
        <x-page-header
            icon="shopping-cart"
            eyebrow="Pembayaran angsuran"
            title="Keranjang pembayaran"
            description="Atur tanggal/metode per angsuran, atau terapkan default ke semua item sebelum checkout."
            :back-href="$backHref"
        >
            <x-slot:actions>
                @if ($fromLoan)
                    <flux:button :href="route('portal.loans.show', ['role' => $role, 'loan' => $fromLoan])" variant="outline" icon="arrow-uturn-left">
                        Kembali ke pinjaman
                    </flux:button>
                @endif
                @if ($rows->isNotEmpty())
                    <form method="POST" action="{{ route('portal.loans.cart.clear', ['role' => $role]) }}" onsubmit="return confirm('Kosongkan seluruh keranjang?')">
                        @csrf
                        @method('DELETE')
                        <flux:button type="submit" variant="ghost" icon="trash">Kosongkan</flux:button>
                    </form>
                @endif
            </x-slot:actions>
        </x-page-header>

        <x-loans.workflow :role="$role" active="cart" />

        <section class="loan-cart-stats">
            <x-stat-card label="Angsuran dipilih" :value="$rows->count().' item'" icon="queue-list" />
            <x-stat-card label="Total pokok + bunga" :value="'Rp '.number_format($grandPrincipal, 0, ',', '.')" icon="banknotes" />
            <x-stat-card label="Sisa denda terbuka" :value="'Rp '.number_format((float) $blocked->sum(fn ($row) => (float) $row->denda - (float) ($row->denda_dibayar ?? 0)), 0, ',', '.')" icon="exclamation-triangle" />
        </section>

        @if ($rows->isNotEmpty())
            <x-loans.cart-summary :role="$role" :grouped="$grouped" :item-count="$rows->count()" />
        @endif

        @if ($blocked->isNotEmpty())
            <aside class="loan-cart-alert">
                <p class="font-semibold">Sisa denda harus dilunasi dulu</p>
                <p class="mt-1 text-amber-900/90">
                    Ada angsuran dengan denda terbuka.
                    Bayar denda dari detail pinjaman sebelum checkout keranjang.
                </p>
            </aside>
        @endif

        @if ($rows->isNotEmpty())
            <form
                method="POST"
                action="{{ route('portal.loans.cart.checkout', ['role' => $role]) }}"
                class="space-y-5"
                x-ref="cartForm"
            >
                @csrf
                @if ($fromLoan)
                    <input type="hidden" name="from" value="{{ $fromLoan }}">
                @endif

                <x-form-section title="Default pembayaran" description="Nilai ini dipakai untuk semua item. Anda masih bisa mengubah per baris di bawah.">
                    <div class="loan-cart-defaults">
                        <x-loans.payment-date-field
                            name="default_tanggal_bayar"
                            label="Tanggal default"
                            x-model="defaultDate"
                        />
                        <x-loans.payment-method
                            name="default_metode"
                            label="Metode default"
                            :value="$defaultMethod"
                            x-model="defaultMethod"
                        />
                        <div class="loan-cart-defaults-action">
                            <x-loans.action-chip type="button" icon="arrow-path" @click="applyDefaults()">
                                Terapkan ke semua
                            </x-loans.action-chip>
                        </div>
                    </div>
                </x-form-section>

                <div class="loan-cart-items">
                    @foreach ($rows as $row)
                        @php
                            $item = $cart[$row->id] ?? [];
                            $residualPenalty = max(0, (float) $row->denda - (float) ($row->denda_dibayar ?? 0));
                            $hasBlock = $residualPenalty > 0.005;
                            $dateValue = old("items.{$row->id}.tanggal_bayar", $item['tanggal_bayar'] ?? $defaultDate);
                            $methodValue = old("items.{$row->id}.metode", $item['metode'] ?? $defaultMethod);
                            $penaltyValue = old("items.{$row->id}.denda", $item['denda'] ?? $row->denda);
                        @endphp
                        <x-loans.cart-item
                            :role="$role"
                            :row="$row"
                            :date-value="$dateValue"
                            :method-value="$methodValue"
                            :penalty-value="$penaltyValue"
                            :has-block="$hasBlock"
                            :residual-penalty="$residualPenalty"
                        />
                    @endforeach
                </div>

                <div class="loan-cart-checkout">
                    <div class="min-w-0">
                        <p class="text-sm text-slate-600">Total pokok + bunga yang akan dibayar</p>
                        <p class="mt-1 text-xl font-bold tracking-tight text-slate-950 sm:text-2xl">
                            Rp {{ number_format($grandPrincipal, 0, ',', '.') }}
                        </p>
                    </div>
                    <div class="loan-cart-checkout-actions">
                        <flux:button type="submit" formaction="{{ route('portal.loans.cart.update', ['role' => $role]) }}" variant="outline">
                            Simpan keranjang
                        </flux:button>
                        <flux:button type="submit" variant="primary" icon="banknotes" :disabled="$blocked->isNotEmpty()">
                            Bayar {{ $rows->count() }} angsuran
                        </flux:button>
                    </div>
                </div>
            </form>

            @foreach ($rows as $row)
                <form id="remove-cart-{{ $row->id }}" method="POST" action="{{ route('portal.loans.cart.remove', ['role' => $role, 'installment' => $row->id]) }}" class="hidden">
                    @csrf
                    @method('DELETE')
                </form>
            @endforeach
        @else
            <x-empty-state icon="shopping-cart" title="Keranjang masih kosong" description="Dari detail pinjaman, centang beberapa angsuran lalu tekan Keranjang terpilih, atau tekan Keranjang per baris.">
                <x-slot:action>
                    <flux:button :href="$backHref" variant="primary">Pilih angsuran</flux:button>
                </x-slot:action>
            </x-empty-state>
        @endif
    </div>
</x-layouts.portal>
