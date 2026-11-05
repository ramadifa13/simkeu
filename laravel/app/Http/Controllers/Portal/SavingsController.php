<?php

namespace App\Http\Controllers\Portal;

use App\Actions\Audit\RecordActivity;
use App\Actions\Savings\CreateSavingsTransactionAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSavingsTransactionRequest;
use App\Models\Anggota;
use App\Models\JenisSimpanan;
use App\Models\Transaksi;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SavingsController extends Controller
{
    /** @var list<string> */
    private const RESERVED_TYPES = ['ANG', 'BONUS', 'SHU'];

    public function index(Request $request, string $role): View
    {
        $this->authorize('manage-savings');

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'jenis' => ['nullable', 'integer'],
            'transaksi' => ['nullable', Rule::in(['setoran', 'penarikan'])],
            'tanggal_dari' => ['nullable', 'date'],
            'tanggal_sampai' => ['nullable', 'date', 'after_or_equal:tanggal_dari'],
            'sort' => ['nullable', Rule::in(['tanggal_transaksi', 'kode_transaksi', 'jumlah', 'jenis_transaksi'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', Rule::in([5, 10, 25, 50])],
        ]);

        $query = Transaksi::query()
            ->with(['member:id,kode_anggota,nama_lengkap', 'savingType:id,kode_jenis,nama_jenis', 'actor:id,nama_lengkap'])
            ->whereIn('jenis_transaksi', ['setoran', 'penarikan']);

        if (filled($filters['q'] ?? null)) {
            $query->where(function ($builder) use ($filters): void {
                $term = '%'.$filters['q'].'%';
                $builder->where('kode_transaksi', 'like', $term)
                    ->orWhereHas('member', fn ($members) => $members
                        ->where('nama_lengkap', 'like', $term)
                        ->orWhere('kode_anggota', 'like', $term));
            });
        }
        if (filled($filters['jenis'] ?? null)) {
            $query->where('jenis_simpanan_id', $filters['jenis']);
        }
        if (filled($filters['transaksi'] ?? null)) {
            $query->where('jenis_transaksi', $filters['transaksi']);
        }
        if (filled($filters['tanggal_dari'] ?? null)) {
            $query->whereDate('tanggal_transaksi', '>=', $filters['tanggal_dari']);
        }
        if (filled($filters['tanggal_sampai'] ?? null)) {
            $query->whereDate('tanggal_transaksi', '<=', $filters['tanggal_sampai']);
        }

        $sort = $filters['sort'] ?? 'tanggal_transaksi';
        $direction = $filters['direction'] ?? 'desc';
        $transactions = $query->orderBy($sort, $direction)->paginate((int) ($filters['per_page'] ?? 10))->withQueryString();

        $summaryQuery = Transaksi::query()->whereIn('jenis_transaksi', ['setoran', 'penarikan']);

        return view('portal.savings.index', [
            'role' => $role,
            'transactions' => $transactions,
            'members' => Anggota::query()->where('status', 'aktif')->orderBy('nama_lengkap')->get(['id', 'kode_anggota', 'nama_lengkap']),
            'types' => $this->transactionTypes(),
            'summary' => [
                'setoranHariIni' => (float) (clone $summaryQuery)->where('jenis_transaksi', 'setoran')->whereDate('tanggal_transaksi', today())->sum('jumlah'),
                'penarikanHariIni' => (float) (clone $summaryQuery)->where('jenis_transaksi', 'penarikan')->whereDate('tanggal_transaksi', today())->sum('jumlah'),
                'saldoKas' => (float) DB::table('kas')->selectRaw("COALESCE(SUM(CASE WHEN jenis = 'masuk' THEN jumlah ELSE -jumlah END), 0) AS saldo")->value('saldo'),
                'rekeningAktif' => (int) DB::table('simpanan')->where('saldo', '>', 0)->count(),
            ],
            'filters' => $filters,
        ]);
    }

    public function show(string $role, Transaksi $transaction): View
    {
        $this->authorize('manage-savings');
        abort_unless(in_array($transaction->jenis_transaksi, ['setoran', 'penarikan'], true), 404);

        return view('portal.savings.show', [
            'role' => $role,
            'transaction' => $transaction->load(['member', 'savingType', 'actor']),
            'cashEntry' => DB::table('kas')->where('transaksi_id', $transaction->id)->first(),
        ]);
    }

    public function memberBook(Request $request, string $role, Anggota $member): View
    {
        $this->authorize('manage-savings');

        return $this->bookView($request, $role, $member, false);
    }

    public function mySavings(Request $request, string $role): View
    {
        abort_unless($role === 'anggota', 404);

        $member = Anggota::query()->where('user_id', $request->user()->id)->firstOrFail();

        return $this->bookView($request, $role, $member, true);
    }

    public function deposit(StoreSavingsTransactionRequest $request, string $role, CreateSavingsTransactionAction $create, RecordActivity $audit): RedirectResponse
    {
        return $this->store($request, $role, 'setoran', $create, $audit);
    }

    public function withdraw(StoreSavingsTransactionRequest $request, string $role, CreateSavingsTransactionAction $create, RecordActivity $audit): RedirectResponse
    {
        return $this->store($request, $role, 'penarikan', $create, $audit);
    }

    private function store(StoreSavingsTransactionRequest $request, string $role, string $kind, CreateSavingsTransactionAction $create, RecordActivity $audit): RedirectResponse
    {
        $data = $request->validated();

        try {
            $transaction = $create->handle($kind, $data, $request->user());
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['jumlah' => $exception->getMessage()]);
        }

        $audit->handle($request->user(), "savings.{$kind}", Transaksi::class, $transaction->id, [
            'kode_transaksi' => $transaction->kode_transaksi,
            'anggota_id' => $transaction->anggota_id,
            'jenis_simpanan_id' => $transaction->jenis_simpanan_id,
            'jumlah' => $transaction->jumlah,
        ], $request);

        return redirect()->route('portal.savings.index', ['role' => $role])->with('status', ucfirst($kind).' berhasil dicatat: '.$transaction->kode_transaksi.'.');
    }

    private function bookView(Request $request, string $role, Anggota $member, bool $personal): View
    {
        $filters = $request->validate([
            'jenis' => ['nullable', 'integer'],
            'tanggal_dari' => ['nullable', 'date'],
            'tanggal_sampai' => ['nullable', 'date', 'after_or_equal:tanggal_dari'],
            'per_page' => ['nullable', Rule::in([5, 10, 25, 50])],
        ]);

        $history = Transaksi::query()
            ->with('savingType:id,kode_jenis,nama_jenis')
            ->where('anggota_id', $member->id)
            ->whereIn('jenis_transaksi', ['setoran', 'penarikan'])
            ->whereHas('savingType', fn ($types) => $types->whereNotIn(DB::raw('UPPER(kode_jenis)'), self::RESERVED_TYPES));

        if (filled($filters['jenis'] ?? null)) {
            $history->where('jenis_simpanan_id', $filters['jenis']);
        }
        if (filled($filters['tanggal_dari'] ?? null)) {
            $history->whereDate('tanggal_transaksi', '>=', $filters['tanggal_dari']);
        }
        if (filled($filters['tanggal_sampai'] ?? null)) {
            $history->whereDate('tanggal_transaksi', '<=', $filters['tanggal_sampai']);
        }

        $allHistory = clone $history;
        $transactions = $history->orderByDesc('tanggal_transaksi')->paginate((int) ($filters['per_page'] ?? 10))->withQueryString();
        $totals = $allHistory->selectRaw("COALESCE(SUM(CASE WHEN jenis_transaksi = 'setoran' THEN jumlah ELSE 0 END), 0) AS setoran, COALESCE(SUM(CASE WHEN jenis_transaksi = 'penarikan' THEN jumlah ELSE 0 END), 0) AS penarikan, COUNT(*) AS transaksi")->first();
        $balances = DB::table('jenis_simpanan as js')->leftJoin('simpanan as s', function ($join) use ($member): void {
            $join->on('s.jenis_simpanan_id', '=', 'js.id')->where('s.anggota_id', '=', $member->id);
        })->where('js.status', 'aktif')->whereNotIn(DB::raw('UPPER(js.kode_jenis)'), self::RESERVED_TYPES)->orderBy('js.id')->get(['js.id', 'js.kode_jenis', 'js.nama_jenis', DB::raw('COALESCE(s.saldo, 0) AS saldo')]);

        return view('portal.savings.book', [
            'role' => $role,
            'member' => $member,
            'personal' => $personal,
            'transactions' => $transactions,
            'balances' => $balances,
            'types' => $this->transactionTypes(),
            'filters' => $filters,
            'totals' => $totals,
        ]);
    }

    /** @return Collection<int, JenisSimpanan> */
    private function transactionTypes()
    {
        return JenisSimpanan::query()
            ->where('status', 'aktif')
            ->whereNotIn(DB::raw('UPPER(kode_jenis)'), self::RESERVED_TYPES)
            ->orderByRaw("FIELD(UPPER(kode_jenis), 'POKOK', 'WAJIB', 'SUKARELA')")
            ->orderBy('nama_jenis')
            ->get(['id', 'kode_jenis', 'nama_jenis', 'minimum', 'maksimum']);
    }
}
