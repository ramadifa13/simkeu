<?php

namespace App\Http\Controllers\Portal;

use App\Actions\Audit\RecordActivity;
use App\Actions\Waserda\CreateWaserdaTransactionAction;
use App\Actions\Waserda\DeleteWaserdaTransactionAction;
use App\Http\Controllers\Controller;
use App\Models\Anggota;
use App\Models\WaserdaTransaction;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class WaserdaController extends Controller
{
    public function index(Request $request, string $role): View
    {
        $this->authorize('manage-waserda');
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'metode' => ['nullable', Rule::in(['tunai', 'transfer'])], 'tanggal_dari' => ['nullable', 'date'], 'tanggal_sampai' => ['nullable', 'date', 'after_or_equal:tanggal_dari'], 'per_page' => ['nullable', Rule::in([5, 10, 25, 50])]]);
        $query = WaserdaTransaction::query()->with(['member:id,kode_anggota,nama_lengkap', 'actor:id,nama_lengkap']);
        if (filled($filters['q'] ?? null)) {
            $query->where(function ($builder) use ($filters): void {
                $term = '%'.$filters['q'].'%';
                $builder->where('kode_transaksi', 'like', $term)->orWhereHas('member', fn ($members) => $members->where('nama_lengkap', 'like', $term)->orWhere('kode_anggota', 'like', $term));
            });
        }
        if (filled($filters['metode'] ?? null)) {
            $query->where('metode_bayar', $filters['metode']);
        }
        if (filled($filters['tanggal_dari'] ?? null)) {
            $query->whereDate('created_at', '>=', $filters['tanggal_dari']);
        }
        if (filled($filters['tanggal_sampai'] ?? null)) {
            $query->whereDate('created_at', '<=', $filters['tanggal_sampai']);
        }
        $transactions = $query->orderByDesc('created_at')->paginate((int) ($filters['per_page'] ?? 10))->withQueryString();
        $totals = WaserdaTransaction::query()->selectRaw('COALESCE(SUM(total_bayar), 0) as nominal, COUNT(*) as transaksi, COALESCE(AVG(total_bayar), 0) as rata_rata')->first();

        return view('portal.waserda.index', ['role' => $role, 'transactions' => $transactions, 'members' => Anggota::query()->where('status', 'aktif')->orderBy('nama_lengkap')->get(['id', 'kode_anggota', 'nama_lengkap']), 'filters' => $filters, 'totals' => $totals]);
    }

    public function mine(Request $request, string $role): View
    {
        abort_unless($role === 'anggota', 403);
        $memberId = (int) Anggota::query()->where('user_id', $request->user()->id)->value('id');
        abort_unless($memberId > 0, 404, 'Akun belum terhubung ke data anggota.');

        $filters = $request->validate([
            'tanggal_dari' => ['nullable', 'date'],
            'tanggal_sampai' => ['nullable', 'date', 'after_or_equal:tanggal_dari'],
            'per_page' => ['nullable', Rule::in([5, 10, 25, 50])],
        ]);
        $query = WaserdaTransaction::query()->where('anggota_id', $memberId);
        if (filled($filters['tanggal_dari'] ?? null)) {
            $query->whereDate('created_at', '>=', $filters['tanggal_dari']);
        }
        if (filled($filters['tanggal_sampai'] ?? null)) {
            $query->whereDate('created_at', '<=', $filters['tanggal_sampai']);
        }
        $transactions = $query->orderByDesc('created_at')->paginate((int) ($filters['per_page'] ?? 10))->withQueryString();
        $totals = WaserdaTransaction::query()->where('anggota_id', $memberId)->selectRaw('COALESCE(SUM(total_bayar), 0) as nominal, COUNT(*) as transaksi')->first();

        return view('portal.waserda.mine', compact('role', 'transactions', 'filters', 'totals'));
    }

    public function store(Request $request, string $role, CreateWaserdaTransactionAction $create, RecordActivity $audit): RedirectResponse
    {
        $this->authorize('manage-waserda');
        $data = $request->validate(['anggota_id' => ['required', 'integer', Rule::exists('anggota', 'id')], 'metode_bayar' => ['required', Rule::in(['tunai', 'transfer'])], 'items' => ['required', 'array', 'min:1', 'max:20'], 'items.*.nama' => ['required', 'string', 'max:100'], 'items.*.harga' => ['required', 'numeric', 'gt:0'], 'items.*.jumlah' => ['required', 'numeric', 'gt:0', 'max:999999']]);
        try {
            $transaction = $create->handle($data, $request->user());
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['items' => $exception->getMessage()]);
        }
        $audit->handle($request->user(), 'waserda.created', WaserdaTransaction::class, $transaction->id, ['kode_transaksi' => $transaction->kode_transaksi, 'anggota_id' => $transaction->anggota_id, 'total_bayar' => $transaction->total_bayar], $request);

        return redirect()->route('portal.waserda.index', ['role' => $role])->with('status', 'Pembelian Waserda berhasil dicatat: '.$transaction->kode_transaksi.'.');
    }

    public function show(string $role, WaserdaTransaction $waserda): View
    {
        $this->authorize('manage-waserda');

        return view('portal.waserda.show', ['role' => $role, 'transaction' => $waserda->load(['member', 'actor']), 'cashEntry' => DB::table('kas')->where('keterangan', 'like', '%'.$waserda->kode_transaksi.'%')->orderByDesc('id')->first()]);
    }

    public function destroy(Request $request, string $role, WaserdaTransaction $waserda, DeleteWaserdaTransactionAction $delete, RecordActivity $audit): RedirectResponse
    {
        $this->authorize('delete-waserda');

        try {
            $snapshot = $delete->handle($waserda);
        } catch (DomainException $exception) {
            return back()->withErrors(['waserda' => $exception->getMessage()]);
        }

        $audit->handle($request->user(), 'waserda.deleted', WaserdaTransaction::class, $waserda->id, $snapshot, $request);

        return redirect()->route('portal.waserda.index', ['role' => $role])
            ->with('status', 'Transaksi Waserda '.$snapshot['kode_transaksi'].' dan mutasi kas terkait berhasil dihapus.');
    }
}
