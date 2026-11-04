<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CashLedgerController extends Controller
{
    public function index(Request $request, string $role): View
    {
        $this->authorize('view-cash-ledger');

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'jenis' => ['nullable', Rule::in(['masuk', 'keluar'])],
            'sumber' => ['nullable', Rule::in(['setoran', 'penarikan', 'waserda', 'angsuran', 'lainnya'])],
            'tanggal_dari' => ['nullable', 'date'],
            'tanggal_sampai' => ['nullable', 'date', 'after_or_equal:tanggal_dari'],
            'per_page' => ['nullable', Rule::in([5, 10, 25, 50])],
        ]);

        $ledger = $this->ledgerQuery($filters);
        $summary = DB::query()
            ->fromSub($ledger, 'ledger')
            ->selectRaw("COALESCE(SUM(CASE WHEN jenis = 'masuk' THEN jumlah ELSE 0 END), 0) AS masuk, COALESCE(SUM(CASE WHEN jenis = 'keluar' THEN jumlah ELSE 0 END), 0) AS keluar, COUNT(*) AS transaksi")
            ->first();
        $balance = (float) DB::table('kas')->orderByDesc('tanggal')->orderByDesc('id')->value('saldo_sesudah');
        $entries = $ledger->orderByDesc('k.tanggal')->orderByDesc('k.id')
            ->paginate((int) ($filters['per_page'] ?? 10))
            ->withQueryString();

        return view('portal.cash.index', compact('role', 'filters', 'entries', 'summary', 'balance'));
    }

    /** @param array<string, mixed> $filters */
    private function ledgerQuery(array $filters): Builder
    {
        $source = "CASE
            WHEN k.keterangan LIKE '%Waserda%' THEN 'waserda'
            WHEN t.keterangan LIKE '%angsuran%' THEN 'angsuran'
            WHEN t.jenis_transaksi = 'setoran' THEN 'setoran'
            WHEN t.jenis_transaksi = 'penarikan' THEN 'penarikan'
            ELSE 'lainnya'
        END";

        $query = DB::table('kas as k')
            ->leftJoin('transaksi as t', function ($join): void {
                $join->on('t.id', '=', 'k.transaksi_id')
                    ->where('k.keterangan', 'not like', '%Waserda%');
            })
            ->leftJoin('anggota as a', 'a.id', '=', 't.anggota_id')
            ->leftJoin('waserda_transaksi as w', function ($join): void {
                $join->on('w.id', '=', 'k.transaksi_id')
                    ->where('k.keterangan', 'like', '%Waserda%');
            })
            ->leftJoin('anggota as wa', 'wa.id', '=', 'w.anggota_id')
            ->select([
                'k.id', 'k.transaksi_id', 'k.tanggal', 'k.jenis', 'k.jumlah', 'k.saldo_sebelum', 'k.saldo_sesudah', 'k.keterangan',
                't.kode_transaksi as savings_code', 't.jenis_transaksi',
                'w.kode_transaksi as waserda_code',
            ])
            ->selectRaw('COALESCE(a.nama_lengkap, wa.nama_lengkap) AS anggota_nama')
            ->selectRaw('COALESCE(a.kode_anggota, wa.kode_anggota) AS anggota_kode')
            ->selectRaw("{$source} AS sumber");

        if (filled($filters['q'] ?? null)) {
            $term = '%'.$filters['q'].'%';
            $query->where(function (Builder $builder) use ($term): void {
                $builder->where('k.keterangan', 'like', $term)
                    ->orWhere('t.kode_transaksi', 'like', $term)
                    ->orWhere('w.kode_transaksi', 'like', $term)
                    ->orWhere('a.nama_lengkap', 'like', $term)
                    ->orWhere('wa.nama_lengkap', 'like', $term);
            });
        }

        if (filled($filters['jenis'] ?? null)) {
            $query->where('k.jenis', $filters['jenis']);
        }

        if (filled($filters['sumber'] ?? null)) {
            $query->whereRaw("{$source} = ?", [$filters['sumber']]);
        }

        if (filled($filters['tanggal_dari'] ?? null)) {
            $query->whereDate('k.tanggal', '>=', $filters['tanggal_dari']);
        }

        if (filled($filters['tanggal_sampai'] ?? null)) {
            $query->whereDate('k.tanggal', '<=', $filters['tanggal_sampai']);
        }

        return $query;
    }
}
