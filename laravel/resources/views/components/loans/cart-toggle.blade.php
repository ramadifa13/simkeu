@props([
    'role',
    'loanId',
    'installmentId',
    'inCart' => false,
])

@if ($inCart)
    <form
        method="POST"
        action="{{ route('portal.loans.cart.remove', ['role' => $role, 'installment' => $installmentId]) }}"
        class="loan-action-form"
    >
        @csrf
        @method('DELETE')
        <x-loans.action-chip type="submit" tone="muted" icon="check" title="Hapus dari keranjang">
            Di keranjang
        </x-loans.action-chip>
    </form>
@else
    <form
        method="POST"
        action="{{ route('portal.loans.installments.cart.add', ['role' => $role, 'loan' => $loanId, 'installment' => $installmentId]) }}"
        class="loan-action-form"
    >
        @csrf
        <x-loans.action-chip type="submit" icon="shopping-cart" title="Tambah ke keranjang">
            Keranjang
        </x-loans.action-chip>
    </form>
@endif
