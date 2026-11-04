<x-layouts.portal :role="$role" title="Bukti pembayaran">
    <div class="mx-auto max-w-3xl space-y-6">
        <x-page-header icon="document-check" eyebrow="Pembayaran berhasil" title="Bukti angsuran" description="Simpan atau cetak bukti pembayaran ini." :back-href="route('portal.loans.show', ['role' => $role, 'loan' => $loan->id])">
            <x-slot:actions>
                <flux:button onclick="window.print()" icon="printer">Cetak</flux:button>
                <flux:button :href="route('portal.documents.payment-receipt', ['role' => $role, 'loan' => $loan->id, 'installment' => $payment->id])" variant="outline" icon="document-arrow-down">PDF</flux:button>
            </x-slot:actions>
        </x-page-header>
        <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm print:shadow-none">
            <div class="flex items-start justify-between border-b border-slate-100 pb-5"><div><p class="text-sm text-slate-500">Nasabah</p><h2 class="text-lg font-semibold text-slate-950">{{ $loan->nama_lengkap }}</h2><p class="font-mono text-xs text-slate-500">{{ $loan->kode_pinjaman }}</p></div><x-status-badge status="lunas" /></div>
            <dl class="mt-5 grid gap-4 sm:grid-cols-2"><div><dt class="text-sm text-slate-500">Angsuran ke</dt><dd class="font-semibold">{{ $payment->angsuran_ke }}</dd></div><div><dt class="text-sm text-slate-500">Tanggal bayar</dt><dd class="font-semibold">{{ \Carbon\Carbon::parse($payment->tanggal_bayar)->translatedFormat('d M Y') }}</dd></div><div><dt class="text-sm text-slate-500">Pokok + bunga</dt><dd class="font-semibold"><x-money :amount="$payment->jumlah_angsuran" /></dd></div><div><dt class="text-sm text-slate-500">Denda</dt><dd class="font-semibold"><x-money :amount="$payment->denda" /></dd></div></dl>
            <div class="mt-6 rounded-xl bg-emerald-50 p-4 text-emerald-900"><p class="text-sm">Total diterima</p><p class="text-2xl font-bold">Rp {{ number_format($payment->jumlah_angsuran + $payment->denda, 0, ',', '.') }}</p></div>
        </section>
    </div>
</x-layouts.portal>
