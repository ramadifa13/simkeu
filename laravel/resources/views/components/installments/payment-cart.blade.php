@props(['count' => 0, 'total' => 'Rp 0', 'member' => null, 'installment' => null, 'method' => null, 'date' => null])

<aside {{ $attributes->merge(['class' => 'ui-installment-cart']) }} aria-label="Keranjang pembayaran angsuran">
    <div class="flex items-center justify-between gap-3"><div><p class="ui-overline">Keranjang pembayaran</p><p class="mt-1 font-semibold text-slate-900">{{ $count }} item siap proses</p></div><span class="ui-cart-total">{{ $total }}</span></div>
    @if ($member)<div class="mt-4 border-y border-slate-100 py-3 text-sm"><p class="font-semibold text-slate-900">{{ $member }} <span class="font-normal text-slate-500">· angsuran ke-{{ $installment }}</span></p><p class="mt-1 text-slate-600">{{ $method }} · {{ $date }}</p><p class="mt-2 font-semibold text-slate-950">{{ $total }}</p></div>@endif
    <div class="mt-4 flex gap-2"><flux:button class="flex-1" variant="outline">Tinjau</flux:button><flux:button class="flex-1" variant="primary" color="teal">Proses semua</flux:button></div>
    <p class="mt-3 text-xs leading-5 text-slate-500">Keranjang harus terlihat dekat daftar agar teller tidak perlu scroll jauh untuk memeriksa item yang dipilih.</p>
</aside>
