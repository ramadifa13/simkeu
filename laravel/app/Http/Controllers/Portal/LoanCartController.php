<?php

namespace App\Http\Controllers\Portal;

use App\Actions\Audit\RecordActivity;
use App\Actions\Loans\CheckoutInstallmentCartAction;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Portal\Concerns\InteractsWithLoans;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LoanCartController extends Controller
{
    use InteractsWithLoans;

    public const CART_KEY = 'loan_installment_cart';

    public function addToCart(Request $request, string $role, int $loan, int $installment): RedirectResponse
    {
        $this->authorize('collect-installments');
        $this->loanForRole($request, $role, $loan);
        abort_unless(DB::table('angsuran')->where('id', $installment)->where('pinjaman_id', $loan)->where('status', '<>', 'lunas')->exists(), 404);
        $cart = $this->cartItems($request);
        $cart[$installment] = $this->normalizeCartItem($loan, $installment, $cart[$installment] ?? []);
        $request->session()->put(self::CART_KEY, $cart);

        return back()->with('status', 'Angsuran ditambahkan ke keranjang.');
    }

    public function bulkAddToCart(Request $request, string $role, int $loan): RedirectResponse
    {
        $this->authorize('collect-installments');
        $this->loanForRole($request, $role, $loan);
        $data = $request->validate([
            'installment_ids' => ['required', 'array', 'min:1'],
            'installment_ids.*' => ['integer', 'distinct'],
        ]);

        $validIds = DB::table('angsuran')
            ->where('pinjaman_id', $loan)
            ->where('status', '<>', 'lunas')
            ->whereIn('id', $data['installment_ids'])
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($validIds === []) {
            throw ValidationException::withMessages(['installment_ids' => 'Tidak ada angsuran valid untuk dimasukkan ke keranjang.']);
        }

        $cart = $this->cartItems($request);
        foreach ($validIds as $installmentId) {
            $cart[$installmentId] = $this->normalizeCartItem($loan, $installmentId, $cart[$installmentId] ?? []);
        }
        $request->session()->put(self::CART_KEY, $cart);

        return back()->with('status', count($validIds).' angsuran ditambahkan ke keranjang.');
    }

    public function removeFromCart(Request $request, string $role, int $installment): RedirectResponse
    {
        $this->authorize('collect-installments');
        $cart = $this->cartItems($request);
        unset($cart[$installment]);
        $request->session()->put(self::CART_KEY, $cart);

        return back()->with('status', 'Angsuran dihapus dari keranjang.');
    }

    public function clearCart(Request $request, string $role): RedirectResponse
    {
        $this->authorize('collect-installments');
        $request->session()->forget(self::CART_KEY);

        return redirect()
            ->route('portal.loans.cart', ['role' => $role])
            ->with('status', 'Keranjang dikosongkan.');
    }

    public function cart(Request $request, string $role): View
    {
        $this->authorize('collect-installments');
        [$rows, $cart] = $this->cartRows($request);
        $request->session()->put(self::CART_KEY, $cart);

        $fromLoan = $request->integer('from') ?: null;
        if ($fromLoan && ! $rows->contains(fn ($row) => (int) $row->pinjaman_id === $fromLoan)) {
            $fromLoan = $rows->first()?->pinjaman_id;
        } elseif (! $fromLoan) {
            $fromLoan = $rows->first()?->pinjaman_id;
        }

        return view('portal.loans.cart', [
            'role' => $role,
            'rows' => $rows,
            'cart' => $cart,
            'fromLoan' => $fromLoan ? (int) $fromLoan : null,
            'defaultDate' => today()->toDateString(),
            'defaultMethod' => 'cash',
        ]);
    }

    public function updateCart(Request $request, string $role): RedirectResponse
    {
        $this->authorize('collect-installments');
        $cart = $this->persistCartOverrides($request);

        return redirect()
            ->route('portal.loans.cart', array_filter(['role' => $role, 'from' => $request->integer('from') ?: null]))
            ->with('status', count($cart).' item keranjang disimpan.');
    }

    public function checkoutCart(Request $request, string $role, CheckoutInstallmentCartAction $checkout, RecordActivity $audit): RedirectResponse
    {
        $this->authorize('collect-installments');
        $cart = $this->persistCartOverrides($request);
        if ($cart === []) {
            throw ValidationException::withMessages(['keranjang' => 'Keranjang angsuran kosong.']);
        }

        $payload = [];
        foreach ($cart as $item) {
            $date = (string) ($item['tanggal_bayar'] ?: today()->toDateString());
            $method = (string) ($item['metode'] ?: 'cash');
            $this->assertInstallmentMonthOpen($date);
            $payload[] = [
                'installment_id' => (int) $item['installment_id'],
                'tanggal_bayar' => $date,
                'metode' => $method,
                'denda' => $item['denda'],
            ];
        }

        // Penalty refresh runs per cart item date inside CheckoutInstallmentCartAction.
        try {
            $result = $checkout->handle($payload, $request->user());
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['keranjang' => $e->getMessage()]);
        }

        $request->session()->forget(self::CART_KEY);
        $audit->handle($request->user(), 'loan.installment.cart.checked_out', 'angsuran', null, [
            'jumlah_item' => count($result),
            'installment_ids' => array_column($payload, 'installment_id'),
        ], $request);

        $redirectLoan = collect($result)->first()['loan_id'] ?? null;

        return redirect()
            ->route(
                $redirectLoan ? 'portal.loans.show' : 'portal.loans.index',
                $redirectLoan
                    ? ['role' => $role, 'loan' => $redirectLoan]
                    : ['role' => $role, 'status' => 'aktif']
            )
            ->with('status', count($result).' angsuran berhasil dibayar dari keranjang.');
    }

    /** @return array<int, array{loan_id:int,installment_id:int,tanggal_bayar:?string,metode:?string,denda:?float}> */
    private function cartItems(Request $request): array
    {
        $raw = $request->session()->get(self::CART_KEY, []);
        $cart = [];
        foreach ($raw as $key => $item) {
            $installmentId = (int) ($item['installment_id'] ?? $key);
            $loanId = (int) ($item['loan_id'] ?? 0);
            if ($installmentId <= 0 || $loanId <= 0) {
                continue;
            }
            $cart[$installmentId] = $this->normalizeCartItem($loanId, $installmentId, is_array($item) ? $item : []);
        }

        return $cart;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{loan_id:int,installment_id:int,tanggal_bayar:?string,metode:?string,denda:?float}
     */
    private function normalizeCartItem(int $loanId, int $installmentId, array $item): array
    {
        $method = $item['metode'] ?? null;
        $method = in_array($method, ['cash', 'transfer'], true) ? $method : null;
        $denda = $item['denda'] ?? null;

        return [
            'loan_id' => $loanId,
            'installment_id' => $installmentId,
            'tanggal_bayar' => filled($item['tanggal_bayar'] ?? null) ? (string) $item['tanggal_bayar'] : null,
            'metode' => $method,
            'denda' => $denda === null || $denda === '' ? null : (float) $denda,
        ];
    }

    /**
     * @return array{0:Collection<int, object>, 1: array<int, array{loan_id:int,installment_id:int,tanggal_bayar:?string,metode:?string,denda:?float}>}
     */
    private function cartRows(Request $request): array
    {
        $cart = $this->cartItems($request);
        $ids = array_keys($cart);
        if ($ids === []) {
            return [collect(), []];
        }

        $query = DB::table('angsuran as i')
            ->join('pinjaman as p', 'p.id', '=', 'i.pinjaman_id')
            ->join('anggota as a', 'a.id', '=', 'p.anggota_id')
            ->whereIn('i.id', $ids)
            ->where('i.status', '<>', 'lunas')
            ->select('i.*', 'p.kode_pinjaman', 'a.nama_lengkap')
            ->orderBy('a.nama_lengkap')
            ->orderBy('i.angsuran_ke');

        if (Schema::hasTable('layanan_bayar_denda')) {
            $query->selectSub(
                DB::table('layanan_bayar_denda')->selectRaw('COALESCE(SUM(jumlah), 0)')->whereColumn('angsuran_id', 'i.id'),
                'denda_dibayar'
            );
        }

        $rows = $query->get();
        if (! Schema::hasTable('layanan_bayar_denda')) {
            $rows->each(function (object $row): void {
                $row->denda_dibayar = 0;
            });
        }

        $validIds = $rows->pluck('id')->map(fn ($id) => (int) $id)->all();
        $cart = array_intersect_key($cart, array_flip($validIds));

        return [$rows, $cart];
    }

    /** @return array<int, array{loan_id:int,installment_id:int,tanggal_bayar:?string,metode:?string,denda:?float}> */
    private function persistCartOverrides(Request $request): array
    {
        $cart = $this->cartItems($request);
        if ($cart === []) {
            return [];
        }

        $rules = [
            'from' => ['nullable', 'integer'],
            'default_tanggal_bayar' => ['nullable', 'date', 'before_or_equal:today'],
            'default_metode' => ['nullable', Rule::in(['cash', 'transfer'])],
            'items' => ['nullable', 'array'],
            'items.*.tanggal_bayar' => ['nullable', 'date', 'before_or_equal:today'],
            'items.*.metode' => ['nullable', Rule::in(['cash', 'transfer'])],
        ];
        if ($request->user()?->role === 'admin') {
            $rules['items.*.denda'] = ['nullable', 'numeric', 'min:0'];
        }

        $data = $request->validate($rules);
        $defaultDate = $data['default_tanggal_bayar'] ?? today()->toDateString();
        $defaultMethod = $data['default_metode'] ?? 'cash';
        $items = $data['items'] ?? [];

        foreach ($cart as $installmentId => $item) {
            $override = $items[$installmentId] ?? [];
            $cart[$installmentId] = $this->normalizeCartItem(
                (int) $item['loan_id'],
                (int) $installmentId,
                [
                    'tanggal_bayar' => $override['tanggal_bayar'] ?? $item['tanggal_bayar'] ?? $defaultDate,
                    'metode' => $override['metode'] ?? $item['metode'] ?? $defaultMethod,
                    'denda' => array_key_exists('denda', $override)
                        ? $override['denda']
                        : ($item['denda'] ?? null),
                ]
            );
        }

        $request->session()->put(self::CART_KEY, $cart);

        return $cart;
    }
}
