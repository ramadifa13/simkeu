<?php

namespace App\Http\Controllers\Portal;

use App\Actions\Audit\RecordActivity;
use App\Actions\Reports\ExportExcelReportAction;
use App\Http\Controllers\Controller;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    private const EXPORT_ROW_LIMIT = 5000;

    private const TYPES = ['simpanan', 'kas', 'anggota', 'waserda', 'denda', 'pinjaman', 'angsuran', 'lunas', 'harian', 'transaksi'];

    /** Mirror native permissions.php: teller may open harian/simpanan/lunas/denda (+ waserda); not pinjaman/angsuran. */
    private const TELLER_TYPES = ['harian', 'simpanan', 'lunas', 'denda', 'waserda'];

    public function index(Request $request, string $role): View
    {
        $this->authorize('view-operational-reports');
        $filters = $this->filters($request, $role);
        $definition = $this->definition($filters);
        $rows = $definition['query']->paginate((int) ($filters['per_page'] ?? 10))->withQueryString();
        $availableTypes = $role === 'teller' ? self::TELLER_TYPES : self::TYPES;

        return view('portal.reports.index', compact('role', 'filters', 'definition', 'rows', 'availableTypes'));
    }

    public function export(Request $request, string $role, RecordActivity $audit): StreamedResponse
    {
        $this->authorize('view-operational-reports');
        $filters = $this->filters($request, $role);
        $definition = $this->definition($filters);
        [$items, $truncated] = $this->exportRows($definition['query']);

        $audit->handle($request->user(), 'report.exported', 'laporan', null, [
            'jenis' => $filters['jenis'],
            'count' => $items->count(),
            'truncated' => $truncated,
            'tanggal_dari' => $filters['tanggal_dari'] ?? null,
            'tanggal_sampai' => $filters['tanggal_sampai'] ?? null,
        ], $request);

        $filename = 'laporan-'.$filters['jenis'].'-'.now()->format('Ymd-His').($truncated ? '-partial' : '').'.csv';

        return response()->streamDownload(function () use ($definition, $items): void {
            $handle = fopen('php://output', 'wb');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, array_values($definition['columns']));
            foreach ($items as $item) {
                fputcsv($handle, array_map(fn (string $key) => data_get($item, $key), array_keys($definition['columns'])));
            }
            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Export-Truncated' => $truncated ? '1' : '0',
            'X-Export-Row-Limit' => (string) self::EXPORT_ROW_LIMIT,
        ]);
    }

    public function exportExcel(Request $request, string $role, ExportExcelReportAction $excel, RecordActivity $audit): StreamedResponse
    {
        $this->authorize('view-operational-reports');
        $filters = $this->filters($request, $role);
        $definition = $this->definition($filters);
        [$items, $truncated] = $this->exportRows($definition['query']);
        $audit->handle($request->user(), 'report.exported_excel', 'laporan', null, [
            'jenis' => $filters['jenis'],
            'count' => $items->count(),
            'truncated' => $truncated,
        ], $request);

        $filename = 'laporan-'.$filters['jenis'].'-'.now()->format('Ymd-His').($truncated ? '-partial' : '').'.xlsx';

        $response = $excel->handle(
            $definition['title'],
            $definition['columns'],
            $items,
            $filename
        );
        $response->headers->set('X-Export-Truncated', $truncated ? '1' : '0');
        $response->headers->set('X-Export-Row-Limit', (string) self::EXPORT_ROW_LIMIT);

        return $response;
    }

    /**
     * @return array{0: \Illuminate\Support\Collection<int, object>, 1: bool}
     */
    private function exportRows(Builder $query): array
    {
        $items = $query->limit(self::EXPORT_ROW_LIMIT + 1)->get();
        $truncated = $items->count() > self::EXPORT_ROW_LIMIT;
        if ($truncated) {
            $items = $items->take(self::EXPORT_ROW_LIMIT)->values();
        }

        return [$items, $truncated];
    }

    /** @return array<string, mixed> */
    private function filters(Request $request, string $role): array
    {
        $allowed = $role === 'teller' ? self::TELLER_TYPES : self::TYPES;
        $filters = $request->validate([
            'jenis' => ['nullable', Rule::in($allowed)],
            'q' => ['nullable', 'string', 'max:100'],
            'sumber' => ['nullable', Rule::in(['TABUNGAN', 'PINJAMAN', 'WASERDA', 'KAS_LAIN'])],
            'tanggal_dari' => ['nullable', 'date'],
            'tanggal_sampai' => ['nullable', 'date', 'after_or_equal:tanggal_dari'],
            'per_page' => ['nullable', Rule::in([5, 10, 25, 50])],
        ]);
        $filters['jenis'] = $filters['jenis'] ?? ($role === 'teller' ? 'harian' : 'simpanan');

        return $filters;
    }

    /** @param array<string, mixed> $filters @return array{title:string,description:string,columns:array<string,string>,money:list<string>,dates:list<string>,query:Builder} */
    private function definition(array $filters): array
    {
        return match ($filters['jenis']) {
            'kas' => $this->cashReport($filters),
            'anggota' => $this->memberReport($filters),
            'waserda' => $this->waserdaReport($filters),
            'denda' => $this->penaltyReport($filters),
            'pinjaman' => $this->loanReport($filters),
            'angsuran' => $this->installmentReport($filters),
            'lunas' => $this->settledLoanReport($filters),
            'harian' => $this->dailyTellerReport($filters),
            'transaksi' => $this->unifiedTransactionReport($filters),
            default => $this->savingsReport($filters),
        };
    }

    /** @param array<string, mixed> $filters */
    private function unifiedTransactionReport(array $filters): array
    {
        $parts = [];
        $parts[] = DB::table('transaksi as t')
            ->join('anggota as a', 'a.id', '=', 't.anggota_id')
            ->join('jenis_simpanan as j', 'j.id', '=', 't.jenis_simpanan_id')
            ->whereIn('t.jenis_transaksi', ['setoran', 'penarikan'])
            ->selectRaw("t.tanggal_transaksi as tanggal, 'TABUNGAN' as sumber, j.nama_jenis as detail, t.kode_transaksi as kode, a.kode_anggota, a.nama_lengkap as anggota, t.jenis_transaksi as tipe, t.jumlah as nominal");

        $loanDate = Schema::hasColumn('pinjaman', 'tanggal_cair') ? 'p.tanggal_cair' : 'p.tanggal_pencairan';
        $parts[] = DB::table('pinjaman as p')
            ->join('anggota as a', 'a.id', '=', 'p.anggota_id')
            ->join('jenis_pinjaman as jp', 'jp.id', '=', 'p.jenis_pinjaman_id')
            ->whereNotNull($loanDate)
            ->selectRaw("{$loanDate} as tanggal, 'PINJAMAN' as sumber, jp.nama_jenis as detail, p.kode_pinjaman as kode, a.kode_anggota, a.nama_lengkap as anggota, 'pencairan' as tipe, p.jumlah_pinjaman as nominal");

        if (Schema::hasTable('waserda_transaksi')) {
            $parts[] = DB::table('waserda_transaksi as w')
                ->join('anggota as a', 'a.id', '=', 'w.anggota_id')
                ->selectRaw("w.created_at as tanggal, 'WASERDA' as sumber, 'Pembelian' as detail, w.kode_transaksi as kode, a.kode_anggota, a.nama_lengkap as anggota, 'waserda' as tipe, w.total_bayar as nominal");
        }

        $parts[] = DB::table('kas as k')
            ->leftJoin('transaksi as t', 't.id', '=', 'k.transaksi_id')
            ->whereNull('t.id')
            ->selectRaw("k.tanggal as tanggal, 'KAS_LAIN' as sumber, COALESCE(k.keterangan, '-') as detail, CONCAT('KAS-', k.id) as kode, '-' as kode_anggota, '-' as anggota, k.jenis as tipe, k.jumlah as nominal");

        $union = array_shift($parts);
        foreach ($parts as $part) {
            $union->unionAll($part);
        }

        $query = DB::query()->fromSub($union, 'trx');
        if (filled($filters['sumber'] ?? null)) {
            $query->where('sumber', $filters['sumber']);
        }
        $this->applyDateAndSearch($query, $filters, 'tanggal', ['kode', 'kode_anggota', 'anggota', 'detail', 'tipe', 'sumber']);

        return [
            'title' => 'Laporan transaksi gabungan',
            'description' => 'Gabungan mutasi tabungan, pencairan pinjaman, waserda, dan kas lain seperti laporan native.',
            'columns' => [
                'tanggal' => 'Tanggal',
                'sumber' => 'Sumber',
                'detail' => 'Detail',
                'kode' => 'Kode',
                'kode_anggota' => 'Kode anggota',
                'anggota' => 'Anggota',
                'tipe' => 'Tipe',
                'nominal' => 'Nominal',
            ],
            'money' => ['nominal'],
            'dates' => ['tanggal'],
            'query' => $query->orderByDesc('tanggal'),
        ];
    }

    /** @param array<string, mixed> $filters */
    private function dailyTellerReport(array $filters): array
    {
        $query = DB::table('transaksi as t')
            ->join('anggota as a', 'a.id', '=', 't.anggota_id')
            ->leftJoin('users as u', 'u.id', '=', 't.user_id')
            ->select(['t.kode_transaksi as kode', 'a.kode_anggota', 'a.nama_lengkap as anggota', 't.jenis_transaksi as transaksi', 't.jumlah as nominal', 'u.nama_lengkap as petugas', 't.tanggal_transaksi as tanggal']);
        if (empty($filters['tanggal_dari']) && empty($filters['tanggal_sampai'])) {
            $query->whereDate('t.tanggal_transaksi', today()->toDateString());
        }
        $this->applyDateAndSearch($query, $filters, 't.tanggal_transaksi', ['t.kode_transaksi', 'a.kode_anggota', 'a.nama_lengkap', 'u.nama_lengkap']);

        return ['title' => 'Laporan harian teller', 'description' => 'Mutasi transaksi operasional per hari kerja.', 'columns' => ['kode' => 'Kode', 'kode_anggota' => 'Kode anggota', 'anggota' => 'Anggota', 'transaksi' => 'Jenis', 'nominal' => 'Nominal', 'petugas' => 'Petugas', 'tanggal' => 'Tanggal'], 'money' => ['nominal'], 'dates' => ['tanggal'], 'query' => $query->orderByDesc('t.tanggal_transaksi')];
    }

    /** @param array<string, mixed> $filters */
    private function savingsReport(array $filters): array
    {
        $query = DB::table('transaksi as t')->join('anggota as a', 'a.id', '=', 't.anggota_id')->join('jenis_simpanan as j', 'j.id', '=', 't.jenis_simpanan_id')->whereIn('t.jenis_transaksi', ['setoran', 'penarikan'])->select(['t.kode_transaksi as kode', 'a.kode_anggota', 'a.nama_lengkap as anggota', 'j.nama_jenis as kategori', 't.jenis_transaksi as transaksi', 't.jumlah as nominal', 't.tanggal_transaksi as tanggal']);
        $this->applyDateAndSearch($query, $filters, 't.tanggal_transaksi', ['t.kode_transaksi', 'a.kode_anggota', 'a.nama_lengkap', 'j.nama_jenis']);

        return ['title' => 'Laporan simpanan', 'description' => 'Setoran dan penarikan simpanan dari ledger operasional.', 'columns' => ['kode' => 'Kode', 'kode_anggota' => 'Kode anggota', 'anggota' => 'Anggota', 'kategori' => 'Jenis simpanan', 'transaksi' => 'Transaksi', 'nominal' => 'Nominal', 'tanggal' => 'Tanggal'], 'money' => ['nominal'], 'dates' => ['tanggal'], 'query' => $query->orderByDesc('t.tanggal_transaksi')];
    }

    /** @param array<string, mixed> $filters */
    private function cashReport(array $filters): array
    {
        $query = DB::table('kas as k')->select(['k.id as kode', 'k.jenis as transaksi', 'k.keterangan', 'k.jumlah as nominal', 'k.saldo_sesudah', 'k.tanggal']);
        $this->applyDateAndSearch($query, $filters, 'k.tanggal', ['k.keterangan']);

        return ['title' => 'Laporan kas', 'description' => 'Seluruh mutasi masuk dan keluar dari buku kas.', 'columns' => ['kode' => 'ID', 'transaksi' => 'Arus', 'keterangan' => 'Keterangan', 'nominal' => 'Nominal', 'saldo_sesudah' => 'Saldo sesudah', 'tanggal' => 'Tanggal'], 'money' => ['nominal', 'saldo_sesudah'], 'dates' => ['tanggal'], 'query' => $query->orderByDesc('k.tanggal')->orderByDesc('k.id')];
    }

    /** @param array<string, mixed> $filters */
    private function memberReport(array $filters): array
    {
        $query = DB::table('anggota as a')->select(['a.kode_anggota', 'a.nama_lengkap as anggota', 'a.telepon', 'a.email', 'a.status', 'a.tanggal_daftar as tanggal']);
        if (filled($filters['q'] ?? null)) {
            $term = '%'.$filters['q'].'%';
            $query->where(fn (Builder $q) => $q->where('a.kode_anggota', 'like', $term)->orWhere('a.nama_lengkap', 'like', $term)->orWhere('a.telepon', 'like', $term));
        }
        if (filled($filters['tanggal_dari'] ?? null)) {
            $query->whereDate('a.tanggal_daftar', '>=', $filters['tanggal_dari']);
        }
        if (filled($filters['tanggal_sampai'] ?? null)) {
            $query->whereDate('a.tanggal_daftar', '<=', $filters['tanggal_sampai']);
        }

        return ['title' => 'Laporan anggota', 'description' => 'Daftar anggota berdasarkan data administratif koperasi.', 'columns' => ['kode_anggota' => 'Kode anggota', 'anggota' => 'Nama lengkap', 'telepon' => 'Telepon', 'email' => 'Email', 'status' => 'Status', 'tanggal' => 'Terdaftar'], 'money' => [], 'dates' => ['tanggal'], 'query' => $query->orderByDesc('a.tanggal_daftar')];
    }

    /** @param array<string, mixed> $filters */
    private function waserdaReport(array $filters): array
    {
        $query = DB::table('waserda_transaksi as w')->join('anggota as a', 'a.id', '=', 'w.anggota_id')->select(['w.kode_transaksi as kode', 'a.kode_anggota', 'a.nama_lengkap as anggota', 'w.metode_bayar as metode', 'w.total_bayar as nominal', 'w.created_at as tanggal']);
        $this->applyDateAndSearch($query, $filters, 'w.created_at', ['w.kode_transaksi', 'a.kode_anggota', 'a.nama_lengkap']);

        return ['title' => 'Laporan Waserda', 'description' => 'Pembelian Waserda yang tercatat pada ledger layanan anggota.', 'columns' => ['kode' => 'Kode', 'kode_anggota' => 'Kode anggota', 'anggota' => 'Anggota', 'metode' => 'Metode', 'nominal' => 'Total bayar', 'tanggal' => 'Tanggal'], 'money' => ['nominal'], 'dates' => ['tanggal'], 'query' => $query->orderByDesc('w.created_at')];
    }

    /** @param array<string, mixed> $filters */
    private function penaltyReport(array $filters): array
    {
        $query = DB::table('angsuran as an')->join('pinjaman as p', 'p.id', '=', 'an.pinjaman_id')->join('anggota as a', 'a.id', '=', 'p.anggota_id')->where('an.denda', '>', 0)->select(['p.kode_pinjaman as kode', 'a.kode_anggota', 'a.nama_lengkap as anggota', 'an.angsuran_ke', 'an.denda as nominal', 'an.status', 'an.tanggal_jatuh_tempo as tanggal']);
        $this->applyDateAndSearch($query, $filters, 'an.tanggal_jatuh_tempo', ['p.kode_pinjaman', 'a.kode_anggota', 'a.nama_lengkap']);

        return ['title' => 'Laporan denda', 'description' => 'Denda pada jadwal angsuran pinjaman.', 'columns' => ['kode' => 'Kode pinjaman', 'kode_anggota' => 'Kode anggota', 'anggota' => 'Anggota', 'angsuran_ke' => 'Angsuran ke', 'nominal' => 'Denda', 'status' => 'Status', 'tanggal' => 'Jatuh tempo'], 'money' => ['nominal'], 'dates' => ['tanggal'], 'query' => $query->orderByDesc('an.tanggal_jatuh_tempo')];
    }

    /** @param array<string, mixed> $filters */
    private function loanReport(array $filters): array
    {
        $query = DB::table('pinjaman as p')
            ->join('anggota as a', 'a.id', '=', 'p.anggota_id')
            ->join('jenis_pinjaman as jp', 'jp.id', '=', 'p.jenis_pinjaman_id')
            ->select([
                'p.kode_pinjaman as kode',
                'a.kode_anggota',
                'a.nama_lengkap as anggota',
                'jp.nama_jenis as produk',
                'p.jumlah_pinjaman as plafon',
                'p.sisa_pokok as sisa',
                'p.status',
                'p.tanggal_pencairan as tanggal',
            ]);
        $this->applyDateAndSearch($query, $filters, 'p.tanggal_pencairan', ['p.kode_pinjaman', 'a.kode_anggota', 'a.nama_lengkap', 'jp.nama_jenis']);

        return [
            'title' => 'Laporan pinjaman',
            'description' => 'Seluruh pinjaman aktif, lunas, atau macet berdasarkan tanggal pencairan.',
            'columns' => [
                'kode' => 'Kode pinjaman',
                'kode_anggota' => 'Kode anggota',
                'anggota' => 'Anggota',
                'produk' => 'Produk',
                'plafon' => 'Plafon',
                'sisa' => 'Sisa pokok',
                'status' => 'Status',
                'tanggal' => 'Tanggal cair',
            ],
            'money' => ['plafon', 'sisa'],
            'dates' => ['tanggal'],
            'query' => $query->orderByDesc('p.tanggal_pencairan')->orderByDesc('p.id'),
        ];
    }

    /** @param array<string, mixed> $filters */
    private function installmentReport(array $filters): array
    {
        $query = DB::table('angsuran as an')
            ->join('pinjaman as p', 'p.id', '=', 'an.pinjaman_id')
            ->join('anggota as a', 'a.id', '=', 'p.anggota_id')
            ->select([
                'p.kode_pinjaman as kode',
                'a.kode_anggota',
                'a.nama_lengkap as anggota',
                'an.angsuran_ke',
                'an.jumlah_angsuran as nominal',
                'an.denda',
                'an.status',
                'an.tanggal_jatuh_tempo as tanggal',
            ]);
        $this->applyDateAndSearch($query, $filters, 'an.tanggal_jatuh_tempo', ['p.kode_pinjaman', 'a.kode_anggota', 'a.nama_lengkap']);

        return [
            'title' => 'Laporan angsuran',
            'description' => 'Jadwal angsuran berdasarkan tanggal jatuh tempo.',
            'columns' => [
                'kode' => 'Kode pinjaman',
                'kode_anggota' => 'Kode anggota',
                'anggota' => 'Anggota',
                'angsuran_ke' => 'Angsuran ke',
                'nominal' => 'Pokok + bunga',
                'denda' => 'Denda',
                'status' => 'Status',
                'tanggal' => 'Jatuh tempo',
            ],
            'money' => ['nominal', 'denda'],
            'dates' => ['tanggal'],
            'query' => $query->orderByDesc('an.tanggal_jatuh_tempo')->orderBy('an.angsuran_ke'),
        ];
    }

    /** @param array<string, mixed> $filters */
    private function settledLoanReport(array $filters): array
    {
        $query = DB::table('pinjaman as p')
            ->join('anggota as a', 'a.id', '=', 'p.anggota_id')
            ->join('jenis_pinjaman as jp', 'jp.id', '=', 'p.jenis_pinjaman_id')
            ->where('p.status', 'lunas')
            ->select([
                'p.kode_pinjaman as kode',
                'a.kode_anggota',
                'a.nama_lengkap as anggota',
                'jp.nama_jenis as produk',
                'p.jumlah_pinjaman as plafon',
                'p.total_pinjaman as total',
                'p.status',
                'p.tanggal_lunas as tanggal',
            ]);
        $this->applyDateAndSearch($query, $filters, 'p.tanggal_lunas', ['p.kode_pinjaman', 'a.kode_anggota', 'a.nama_lengkap', 'jp.nama_jenis']);

        return [
            'title' => 'Laporan pinjaman lunas',
            'description' => 'Pinjaman yang sudah lunas berdasarkan tanggal pelunasan.',
            'columns' => [
                'kode' => 'Kode pinjaman',
                'kode_anggota' => 'Kode anggota',
                'anggota' => 'Anggota',
                'produk' => 'Produk',
                'plafon' => 'Plafon',
                'total' => 'Total tagihan',
                'status' => 'Status',
                'tanggal' => 'Tanggal lunas',
            ],
            'money' => ['plafon', 'total'],
            'dates' => ['tanggal'],
            'query' => $query->orderByDesc('p.tanggal_lunas')->orderByDesc('p.id'),
        ];
    }

    /** @param array<string, mixed> $filters @param list<string> $searchColumns */
    private function applyDateAndSearch(Builder $query, array $filters, string $dateColumn, array $searchColumns): void
    {
        if (filled($filters['q'] ?? null)) {
            $term = '%'.$filters['q'].'%';
            $query->where(fn (Builder $nested) => collect($searchColumns)->each(fn (string $column, int $index) => $index === 0 ? $nested->where($column, 'like', $term) : $nested->orWhere($column, 'like', $term)));
        }
        if (filled($filters['tanggal_dari'] ?? null)) {
            $query->whereDate($dateColumn, '>=', $filters['tanggal_dari']);
        }
        if (filled($filters['tanggal_sampai'] ?? null)) {
            $query->whereDate($dateColumn, '<=', $filters['tanggal_sampai']);
        }
    }
}
