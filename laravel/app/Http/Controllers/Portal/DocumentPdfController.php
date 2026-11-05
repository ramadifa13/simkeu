<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Anggota;
use App\Support\DocumentText;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/** Streams printable documents matching native cetak_* templates. */
class DocumentPdfController extends Controller
{
    public function agreement(Request $request, string $role, int $loan): SymfonyResponse|View
    {
        $this->authorizeLoan($request, $role, $loan);
        abort_unless(Schema::hasTable('perjanjian_pinjaman'), 404);
        $data = $this->loanBundle($loan);
        $agreement = DB::table('perjanjian_pinjaman')->where('pinjaman_id', $loan)->orderByDesc('id')->first();
        abort_unless($agreement, 404);
        $snapshot = json_decode((string) ($agreement->snapshot_json ?? ''), true) ?: [];
        $settings = Schema::hasTable('pengaturan_perjanjian')
            ? DB::table('pengaturan_perjanjian')->where('id', 1)->first()
            : null;
        $installments = DB::table('angsuran')->where('pinjaman_id', $loan)->orderBy('angsuran_ke')->get();

        return $this->print('print.agreement', [
            'loan' => $data['loan'],
            'agreement' => $agreement,
            'snapshot' => $snapshot,
            'settings' => $settings,
            'installments' => $installments,
            'cooperative' => $data['cooperative'],
        ]);
    }

    public function disbursementSlip(Request $request, string $role, int $loan): SymfonyResponse|View
    {
        $this->authorizeLoan($request, $role, $loan);
        $data = $this->loanBundle($loan);
        $loanRow = $data['loan'];
        $angsuranPeriode = (float) ($loanRow->angsuran_per_bulan ?? 0);
        $last = DB::table('angsuran')->where('pinjaman_id', $loan)->orderByDesc('angsuran_ke')->first();
        $jumlahCicilan = (int) ($loanRow->jumlah_cicilan ?: DB::table('angsuran')->where('pinjaman_id', $loan)->count());
        $ringkasan = DocumentText::frequency($loanRow->frekuensi ?? 'bulanan').' — '.$jumlahCicilan.'× Rp '
            .number_format(round($angsuranPeriode), 0, ',', '.');

        return $this->print('print.disbursement-slip', [
            'loan' => $loanRow,
            'cooperative' => $data['cooperative'],
            'ringkasanAngsuran' => $ringkasan,
            'angsuranPeriode' => $angsuranPeriode,
            'angsuranTerakhir' => $last ? (float) $last->jumlah_angsuran : null,
            'labelPetugas' => $request->user()->role === 'admin' ? 'ADMIN' : 'TELLER',
            'namaPetugas' => (string) $request->user()->nama_lengkap,
        ]);
    }

    public function installmentBook(Request $request, string $role, int $loan): SymfonyResponse|View
    {
        $this->authorizeLoan($request, $role, $loan);
        $data = $this->loanBundle($loan);
        $installments = DB::table('angsuran')->where('pinjaman_id', $loan)->orderBy('angsuran_ke')->orderBy('id')->get();
        abort_unless($installments->isNotEmpty(), 404, 'Jadwal angsuran belum tersedia.');

        return $this->print('print.installment-book', [
            'loan' => $data['loan'],
            'installments' => $installments,
            'cooperative' => $data['cooperative'],
        ]);
    }

    public function paymentReceipt(Request $request, string $role, int $loan, int $installment): SymfonyResponse|View
    {
        $this->authorizeLoan($request, $role, $loan);
        $data = $this->loanBundle($loan);
        $payment = DB::table('angsuran')->where('id', $installment)->where('pinjaman_id', $loan)->first();
        abort_unless($payment, 404);
        abort_unless($payment->status === 'lunas', 403, 'Angsuran ini belum dibayar, bukti pembayaran belum bisa dicetak.');

        $totalCicilan = (int) DB::table('angsuran')->where('pinjaman_id', $loan)->count();
        $sisaTagihan = (float) DB::table('angsuran')->where('pinjaman_id', $loan)->where('status', '<>', 'lunas')->sum('jumlah_angsuran');
        $denda = (float) $payment->denda;
        $jumlahAngsuran = (float) $payment->jumlah_angsuran;
        $pelunasan = ($payment->cara_bayar ?? null) === 'pelunasan';
        $ketAngsuran = 'Ke-'.$payment->angsuran_ke.' dari '.$totalCicilan;

        if ($pelunasan) {
            $pl = DB::table('angsuran')->where('pinjaman_id', $loan)->where('cara_bayar', 'pelunasan')
                ->selectRaw('MIN(angsuran_ke) as dari, MAX(angsuran_ke) as sampai, SUM(jumlah_angsuran) as jumlah, SUM(denda) as denda')
                ->first();
            $jumlahAngsuran = (float) ($pl->jumlah ?? 0);
            $denda = (float) ($pl->denda ?? 0);
            $ketAngsuran = 'Pelunasan (cicilan ke-'.($pl->dari ?? '?').' s.d. '.($pl->sampai ?? '?').' dari '.$totalCicilan.')';
        } elseif ($data['loan']->status === 'lunas' && (int) $payment->angsuran_ke === $totalCicilan) {
            $ketAngsuran = 'Lunas (cicilan ke-'.$payment->angsuran_ke.' dari '.$totalCicilan.')';
        }

        $totalBayar = $jumlahAngsuran + $denda;
        if (Schema::hasTable('layanan_pembayaran')) {
            $query = DB::table('layanan_pembayaran')->where('pinjaman_id', $loan)->whereNull('dibatalkan_at');
            $terima = $pelunasan
                ? $query->where('jenis', 'pelunasan')->orderByDesc('id')->value('jumlah')
                : $query->where('jenis', 'angsuran')->where('angsuran_id', $installment)->orderByDesc('id')->value('jumlah');
            if ($terima !== null) {
                $totalBayar = (float) $terima;
            }
        }

        $tellerNama = (string) (DB::table('transaksi as t')
            ->join('users as u', 'u.id', '=', 't.user_id')
            ->where('t.keterangan', 'Pembayaran angsuran ke-'.$payment->angsuran_ke.' - '.$data['loan']->kode_pinjaman)
            ->orderByDesc('t.id')
            ->value('u.nama_lengkap') ?? '');
        if ($tellerNama === '' && $request->user()->role === 'teller') {
            $tellerNama = (string) $request->user()->nama_lengkap;
        }

        return $this->print('print.payment-receipt', [
            'loan' => $data['loan'],
            'payment' => $payment,
            'cooperative' => $data['cooperative'],
            'jumlahAngsuran' => $jumlahAngsuran,
            'denda' => $denda,
            'totalBayar' => $totalBayar,
            'sisaTagihan' => $sisaTagihan,
            'ketAngsuran' => $ketAngsuran,
            'pinjamanLunas' => $sisaTagihan <= 0.005 && $totalCicilan > 0,
            'tellerNama' => $tellerNama,
        ]);
    }

    public function settlementReceipt(Request $request, string $role, int $loan): SymfonyResponse|View
    {
        $this->authorizeLoan($request, $role, $loan);
        $data = $this->loanBundle($loan);
        $totalCicilan = (int) DB::table('angsuran')->where('pinjaman_id', $loan)->count();
        $cicilanBelum = (int) DB::table('angsuran')->where('pinjaman_id', $loan)->where('status', '<>', 'lunas')->count();
        abort_unless($totalCicilan > 0 && $cicilanBelum === 0, 403, 'Pinjaman ini belum lunas, bukti pelunasan belum bisa dicetak.');

        $totalAngsuranDibayar = (float) DB::table('angsuran')->where('pinjaman_id', $loan)->where('status', 'lunas')->sum('jumlah_angsuran');
        $totalDenda = (float) DB::table('angsuran')->where('pinjaman_id', $loan)->where('status', 'lunas')->sum('denda');
        $bayarTerakhir = DB::table('angsuran')->where('pinjaman_id', $loan)->max(DB::raw('DATE(tanggal_bayar)'));
        $tanggalLunas = $data['loan']->tanggal_lunas ?: $bayarTerakhir;
        $totalDibayar = $totalAngsuranDibayar + $totalDenda;

        if (Schema::hasTable('layanan_pelunasan')) {
            $diskon = DB::table('layanan_pelunasan as l')
                ->where('l.pinjaman_id', $loan)
                ->where('l.status', 'selesai')
                ->selectRaw("l.diskon_persen, (SELECT COALESCE(SUM(bunga),0) FROM angsuran WHERE pinjaman_id=l.pinjaman_id AND cara_bayar='pelunasan') as sisa_bunga")
                ->first();
            if ($diskon) {
                $totalDibayar -= round(((float) $diskon->sisa_bunga) * ((float) $diskon->diskon_persen) / 100, 2);
            }
        }

        $template = '';
        if (Schema::hasTable('layanan_pengaturan')) {
            $template = (string) (DB::table('layanan_pengaturan')->where('id', 1)->value('surat_lunas') ?? '');
        }
        if (trim($template) === '') {
            $template = 'Dengan ini dinyatakan bahwa pinjaman {norek} atas nama {nama} telah LUNAS pada {tanggal}.';
        }

        $ttd = DB::table('pinjaman as p')
            ->leftJoin('pengajuan_pinjaman as pp', 'pp.id', '=', 'p.pengajuan_id')
            ->leftJoin('users as ua', 'ua.id', '=', 'pp.admin_id')
            ->leftJoin('users as ut', 'ut.id', '=', 'p.dicairkan_oleh')
            ->where('p.id', $loan)
            ->first(['ua.nama_lengkap as admin_nama', 'ut.nama_lengkap as teller_nama']);

        $khususAgtj = str_starts_with((string) $data['loan']->kode_anggota, 'AGTJ')
            || str_starts_with((string) ($data['loan']->kode_anggota_jaminan ?? ''), 'AGTJ');

        return $this->print('print.settlement-receipt', [
            'loan' => $data['loan'],
            'cooperative' => $data['cooperative'],
            'totalCicilan' => $totalCicilan,
            'totalAngsuranDibayar' => $totalAngsuranDibayar,
            'totalDenda' => $totalDenda,
            'totalDibayar' => $totalDibayar,
            'tanggalLunas' => $tanggalLunas,
            'keteranganLunas' => DocumentText::fillTemplate($template, [
                '{nama}' => $data['loan']->nama_lengkap,
                '{norek}' => $data['loan']->kode_pinjaman,
                '{tanggal}' => $tanggalLunas ? DocumentText::dateId($tanggalLunas) : '-',
            ]),
            'jenisPenutupan' => $this->closureType($loan),
            'khususAgtj' => $khususAgtj,
            'namaAdmin' => (string) ($ttd->admin_nama ?? ($request->user()->role === 'admin' ? $request->user()->nama_lengkap : '')),
            'namaTeller' => (string) ($ttd->teller_nama ?? ($request->user()->role === 'teller' ? $request->user()->nama_lengkap : '')),
        ]);
    }

    public function shuReceipt(Request $request, string $role, int $entitlement): SymfonyResponse|View
    {
        $this->authorize('view-own-shu');
        abort_unless(Schema::hasTable('shu_anggota'), 404);
        $row = DB::table('shu_anggota as sa')
            ->join('shu_periode as sp', 'sp.id', '=', 'sa.shu_periode_id')
            ->leftJoin('shu_pembayaran as spay', 'spay.id', '=', 'sa.pembayaran_id')
            ->where('sa.id', $entitlement)
            ->select(['sa.*', 'sp.tahun', 'sp.nama_periode', 'spay.metode', 'spay.referensi', 'spay.created_at as dibayar_at'])
            ->first();
        abort_unless($row, 404);
        $user = $request->user();
        if ($user->role === 'anggota') {
            $memberId = (int) Anggota::query()->where('user_id', $user->id)->value('id');
            abort_unless($memberId === (int) $row->anggota_id, 403);
        }
        $cooperative = DB::table('koperasi')->orderBy('id')->first();

        return $this->print('print.shu-receipt', compact('row', 'cooperative'));
    }

    public function report(Request $request, string $role): SymfonyResponse
    {
        $this->authorize('view-operational-reports');
        $allowed = $role === 'teller'
            ? ['harian', 'simpanan', 'angsuran', 'pinjaman', 'waserda', 'denda']
            : ['simpanan', 'kas', 'anggota', 'waserda', 'denda', 'pinjaman', 'angsuran', 'lunas', 'harian', 'transaksi'];
        $jenis = (string) $request->query('jenis', $role === 'teller' ? 'harian' : 'simpanan');
        abort_unless(in_array($jenis, $allowed, true), 404);
        $rows = match ($jenis) {
            'kas' => DB::table('kas')->orderByDesc('id')->limit(500)->get(),
            'pinjaman' => DB::table('pinjaman as p')->join('anggota as a', 'a.id', '=', 'p.anggota_id')->orderByDesc('p.id')->limit(500)->get(['p.kode_pinjaman', 'a.nama_lengkap', 'p.status', 'p.jumlah_pinjaman', 'p.sisa_pokok']),
            'angsuran' => DB::table('angsuran as i')->join('pinjaman as p', 'p.id', '=', 'i.pinjaman_id')->orderByDesc('i.id')->limit(500)->get(['p.kode_pinjaman', 'i.angsuran_ke', 'i.status', 'i.jumlah_angsuran', 'i.tanggal_jatuh_tempo']),
            'waserda' => Schema::hasTable('waserda_transaksi')
                ? DB::table('waserda_transaksi as w')->join('anggota as a', 'a.id', '=', 'w.anggota_id')->orderByDesc('w.id')->limit(500)->get(['w.kode_transaksi', 'a.nama_lengkap', 'w.metode_bayar', 'w.total_bayar', 'w.created_at'])
                : collect(),
            'denda' => DB::table('angsuran as an')->join('pinjaman as p', 'p.id', '=', 'an.pinjaman_id')->join('anggota as a', 'a.id', '=', 'p.anggota_id')->where('an.denda', '>', 0)->orderByDesc('an.id')->limit(500)->get(['p.kode_pinjaman', 'a.nama_lengkap', 'an.angsuran_ke', 'an.denda', 'an.status']),
            'lunas' => DB::table('pinjaman as p')->join('anggota as a', 'a.id', '=', 'p.anggota_id')->where('p.status', 'lunas')->orderByDesc('p.id')->limit(500)->get(['p.kode_pinjaman', 'a.nama_lengkap', 'p.jumlah_pinjaman', 'p.tanggal_lunas']),
            'harian' => DB::table('transaksi as t')->join('anggota as a', 'a.id', '=', 't.anggota_id')->whereDate('t.tanggal_transaksi', today()->toDateString())->orderByDesc('t.id')->limit(500)->get(['t.kode_transaksi', 'a.nama_lengkap', 't.jenis_transaksi', 't.jumlah', 't.tanggal_transaksi']),
            'transaksi' => $this->unifiedTransactionRows($request),
            default => DB::table('transaksi as t')->join('anggota as a', 'a.id', '=', 't.anggota_id')->orderByDesc('t.id')->limit(500)->get(['t.kode_transaksi', 'a.nama_lengkap', 't.jenis_transaksi', 't.jumlah', 't.tanggal_transaksi']),
        };
        $cooperative = DB::table('koperasi')->orderBy('id')->first();

        return Pdf::loadView('pdf.report', compact('jenis', 'rows', 'cooperative'))->setPaper('a4')->download('laporan-'.$jenis.'-'.now()->format('Ymd').'.pdf');
    }

    /** @return \Illuminate\Support\Collection<int, object> */
    private function unifiedTransactionRows(Request $request): \Illuminate\Support\Collection
    {
        $from = $request->query('tanggal_dari');
        $until = $request->query('tanggal_sampai');
        $sumber = (string) $request->query('sumber', '');

        $parts = [];
        $parts[] = DB::table('transaksi as t')
            ->join('anggota as a', 'a.id', '=', 't.anggota_id')
            ->join('jenis_simpanan as j', 'j.id', '=', 't.jenis_simpanan_id')
            ->whereIn('t.jenis_transaksi', ['setoran', 'penarikan'])
            ->when($from, fn ($q) => $q->whereDate('t.tanggal_transaksi', '>=', $from))
            ->when($until, fn ($q) => $q->whereDate('t.tanggal_transaksi', '<=', $until))
            ->selectRaw("t.tanggal_transaksi as tanggal, 'TABUNGAN' as sumber, j.nama_jenis as detail, t.kode_transaksi as kode, a.nama_lengkap as anggota, t.jenis_transaksi as tipe, t.jumlah as nominal");

        $loanDate = Schema::hasColumn('pinjaman', 'tanggal_cair') ? 'tanggal_cair' : 'tanggal_pencairan';
        $parts[] = DB::table('pinjaman as p')
            ->join('anggota as a', 'a.id', '=', 'p.anggota_id')
            ->join('jenis_pinjaman as jp', 'jp.id', '=', 'p.jenis_pinjaman_id')
            ->whereNotNull('p.'.$loanDate)
            ->when($from, fn ($q) => $q->whereDate('p.'.$loanDate, '>=', $from))
            ->when($until, fn ($q) => $q->whereDate('p.'.$loanDate, '<=', $until))
            ->selectRaw("p.{$loanDate} as tanggal, 'PINJAMAN' as sumber, jp.nama_jenis as detail, p.kode_pinjaman as kode, a.nama_lengkap as anggota, 'pencairan' as tipe, p.jumlah_pinjaman as nominal");

        if (Schema::hasTable('waserda_transaksi')) {
            $parts[] = DB::table('waserda_transaksi as w')
                ->join('anggota as a', 'a.id', '=', 'w.anggota_id')
                ->when($from, fn ($q) => $q->whereDate('w.created_at', '>=', $from))
                ->when($until, fn ($q) => $q->whereDate('w.created_at', '<=', $until))
                ->selectRaw("w.created_at as tanggal, 'WASERDA' as sumber, 'Pembelian' as detail, w.kode_transaksi as kode, a.nama_lengkap as anggota, 'waserda' as tipe, w.total_bayar as nominal");
        }

        $parts[] = DB::table('kas as k')
            ->leftJoin('transaksi as t', 't.id', '=', 'k.transaksi_id')
            ->whereNull('t.id')
            ->when($from, fn ($q) => $q->whereDate('k.tanggal', '>=', $from))
            ->when($until, fn ($q) => $q->whereDate('k.tanggal', '<=', $until))
            ->selectRaw("k.tanggal as tanggal, 'KAS_LAIN' as sumber, COALESCE(k.keterangan, '-') as detail, CONCAT('KAS-', k.id) as kode, '-' as anggota, k.jenis as tipe, k.jumlah as nominal");

        $union = array_shift($parts);
        foreach ($parts as $part) {
            $union->unionAll($part);
        }

        $rows = DB::query()->fromSub($union, 'trx')->orderByDesc('tanggal')->limit(500)->get();
        if ($sumber !== '') {
            $rows = $rows->where('sumber', $sumber)->values();
        }

        return $rows;
    }

    public function draftAgreement(Request $request, string $role, int $application): SymfonyResponse|View
    {
        $this->authorize('manage-loan-applications');
        $row = DB::table('pengajuan_pinjaman as pp')
            ->join('jenis_pinjaman as jp', 'jp.id', '=', 'pp.jenis_pinjaman_id')
            ->join('anggota as a', 'a.id', '=', 'pp.anggota_id')
            ->leftJoin('jaminan_pinjaman as j', 'j.pengajuan_id', '=', 'pp.id')
            ->where('pp.id', $application)
            ->whereIn('pp.status', ['disetujui', 'dicairkan'])
            ->select(['pp.*', 'jp.nama_jenis', 'jp.memerlukan_jaminan', 'a.nama_lengkap', 'a.kode_anggota', 'a.kode_anggota_jaminan', 'a.ktp', 'a.alamat', 'a.telepon', 'a.tempat_lahir', 'a.tanggal_lahir', 'j.jenis_barang', 'j.identitas_barang', 'j.deskripsi_barang'])
            ->first();
        abort_unless($row, 404);
        $cooperative = DB::table('koperasi')->orderBy('id')->first();
        $settings = Schema::hasTable('pengaturan_perjanjian')
            ? DB::table('pengaturan_perjanjian')->where('id', 1)->first()
            : null;

        return $this->print('print.draft-agreement', [
            'application' => $row,
            'cooperative' => $cooperative,
            'settings' => $settings,
        ]);
    }

    public function layananReceipt(Request $request, string $role, string $jenis, int $id): SymfonyResponse|View
    {
        $this->authorize('manage-layanan');
        abort_unless(in_array($jenis, ['denda', 'bayar', 'lelang', 'bonus'], true), 404);
        if ($jenis === 'bonus') {
            $this->authorize('decide-layanan');
        }

        $tables = [
            'denda' => 'layanan_bayar_denda',
            'bayar' => 'layanan_pembayaran',
            'lelang' => 'layanan_lelang',
            'bonus' => 'layanan_bonus',
        ];
        $table = $tables[$jenis];
        abort_unless(Schema::hasTable($table), 404);

        $query = DB::table($table.' as b')
            ->join('pinjaman as p', 'p.id', '=', 'b.pinjaman_id')
            ->join('anggota as ag', 'ag.id', '=', 'p.anggota_id')
            ->join('users as u', 'u.id', '=', 'b.user_id')
            ->where('b.id', $id)
            ->select(['b.*', 'p.kode_pinjaman', 'ag.nama_lengkap', 'ag.kode_anggota', 'ag.ktp', 'u.nama_lengkap as petugas']);

        if ($jenis === 'denda') {
            $query->leftJoin('angsuran as a', 'a.id', '=', 'b.angsuran_id')
                ->addSelect(['a.angsuran_ke', 'a.tanggal_jatuh_tempo']);
        }

        $row = $query->first();
        abort_unless($row, 404);
        if ($jenis === 'lelang' && ! isset($row->jumlah)) {
            $row->jumlah = $row->harga_jual ?? 0;
        }

        if ($jenis === 'denda' && $request->user()->role !== 'admin' && (int) $row->user_id !== (int) $request->user()->id) {
            abort(404);
        }
        if ($jenis === 'bayar' && filled($row->dibatalkan_at ?? null)) {
            abort(410, 'Pembayaran ini sudah dibatalkan.');
        }

        $cooperative = DB::table('koperasi')->orderBy('id')->first();

        return $this->print('print.layanan-receipt', compact('jenis', 'row', 'cooperative'));
    }

    public function bast(Request $request, string $role, int $loan): SymfonyResponse|View
    {
        $this->authorizeLoan($request, $role, $loan);
        abort_unless(Schema::hasTable('layanan_jaminan_kembali'), 404);
        $data = $this->loanBundle($loan);
        $bast = DB::table('layanan_jaminan_kembali as b')
            ->join('users as u', 'u.id', '=', 'b.user_id')
            ->where('b.pinjaman_id', $loan)
            ->where('b.status', 'diserahkan')
            ->orderByDesc('b.id')
            ->first(['b.*', 'u.nama_lengkap as petugas']);
        abort_unless($bast, 404);
        $collateral = Schema::hasTable('jaminan_pinjaman')
            ? DB::table('jaminan_pinjaman')->where('pinjaman_id', $loan)->first()
            : null;
        $member = DB::table('anggota')->where('id', $data['loan']->anggota_id)->first(['ktp']);

        return $this->print('print.bast', [
            'loan' => $data['loan'],
            'bast' => $bast,
            'collateral' => $collateral,
            'cooperative' => $data['cooperative'],
            'ktp' => $member->ktp ?? '-',
        ]);
    }

    public function shuPeriodReport(Request $request, string $role, int $period): SymfonyResponse
    {
        $this->authorize('manage-shu');
        abort_unless(Schema::hasTable('shu_periode'), 404);
        $detail = DB::table('shu_periode')->where('id', $period)->first();
        abort_unless($detail, 404);
        $members = DB::table('shu_anggota')->where('shu_periode_id', $period)->orderByDesc('total_shu')->get();
        $cooperative = DB::table('koperasi')->orderBy('id')->first();

        return Pdf::loadView('pdf.shu-period', compact('detail', 'members', 'cooperative'))->setPaper('a4')->download('laporan-shu-'.$detail->tahun.'.pdf');
    }

    private function authorizeLoan(Request $request, string $role, int $loanId): void
    {
        $this->authorize('view-loans');
        $user = $request->user();
        if ($user->role === 'anggota') {
            $memberId = (int) Anggota::query()->where('user_id', $user->id)->value('id');
            $owned = DB::table('pinjaman')->where('id', $loanId)->where('anggota_id', $memberId)->exists();
            abort_unless($owned, 403);
        }
    }

    /** @return array{loan: object, cooperative: ?object} */
    private function loanBundle(int $loanId): array
    {
        $loan = DB::table('pinjaman as p')
            ->join('anggota as a', 'a.id', '=', 'p.anggota_id')
            ->join('jenis_pinjaman as jp', 'jp.id', '=', 'p.jenis_pinjaman_id')
            ->leftJoin('pengajuan_pinjaman as pp', 'pp.id', '=', 'p.pengajuan_id')
            ->where('p.id', $loanId)
            ->select([
                'p.*',
                'a.nama_lengkap',
                'a.kode_anggota',
                'a.kode_anggota_jaminan',
                'a.alamat',
                'a.telepon',
                'a.ktp',
                'a.tempat_lahir',
                'a.tanggal_lahir',
                'jp.nama_jenis',
                'jp.memerlukan_jaminan',
                'pp.kode_pengajuan',
                'pp.tujuan_pinjaman',
            ])
            ->first();
        abort_unless($loan, 404);

        $loan->kode_anggota_tampil = ! empty($loan->memerlukan_jaminan)
            ? ($loan->kode_anggota_jaminan ?: $loan->kode_anggota)
            : $loan->kode_anggota;

        return [
            'loan' => $loan,
            'cooperative' => DB::table('koperasi')->orderBy('id')->first(),
        ];
    }

    private function closureType(int $loanId): string
    {
        if (Schema::hasTable('layanan_lelang') && DB::table('layanan_lelang')->where('pinjaman_id', $loanId)->exists()) {
            return 'Lelang';
        }
        if (Schema::hasTable('layanan_pelunasan') && DB::table('layanan_pelunasan')->where('pinjaman_id', $loanId)->where('status', 'selesai')->exists()) {
            return 'Pelunasan dini';
        }

        return 'Lunas';
    }

    /** @param  array<string, mixed>  $data */
    private function print(string $view, array $data): View
    {
        return view($view, $data);
    }
}
