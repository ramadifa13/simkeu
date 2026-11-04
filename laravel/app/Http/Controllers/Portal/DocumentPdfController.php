<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Anggota;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/** Streams authorized DomPDF documents for Phase 8 outputs. */
class DocumentPdfController extends Controller
{
    public function agreement(Request $request, string $role, int $loan): SymfonyResponse
    {
        $this->authorizeLoan($request, $role, $loan);
        abort_unless(Schema::hasTable('perjanjian_pinjaman'), 404);
        $data = $this->loanBundle($loan);
        $agreement = DB::table('perjanjian_pinjaman')->where('pinjaman_id', $loan)->orderByDesc('id')->first();
        abort_unless($agreement, 404);

        return $this->pdf('pdf.agreement', ['loan' => $data['loan'], 'agreement' => $agreement, 'cooperative' => $data['cooperative']], 'perjanjian-'.$data['loan']->kode_pinjaman.'.pdf');
    }

    public function disbursementSlip(Request $request, string $role, int $loan): SymfonyResponse
    {
        $this->authorizeLoan($request, $role, $loan);
        $data = $this->loanBundle($loan);

        return $this->pdf('pdf.disbursement-slip', $data, 'slip-pencairan-'.$data['loan']->kode_pinjaman.'.pdf');
    }

    public function installmentBook(Request $request, string $role, int $loan): SymfonyResponse
    {
        $this->authorizeLoan($request, $role, $loan);
        $data = $this->loanBundle($loan);
        $installments = DB::table('angsuran')->where('pinjaman_id', $loan)->orderBy('angsuran_ke')->get();

        return $this->pdf('pdf.installment-book', ['loan' => $data['loan'], 'installments' => $installments, 'cooperative' => $data['cooperative']], 'buku-angsuran-'.$data['loan']->kode_pinjaman.'.pdf');
    }

    public function paymentReceipt(Request $request, string $role, int $loan, int $installment): SymfonyResponse
    {
        $this->authorizeLoan($request, $role, $loan);
        $data = $this->loanBundle($loan);
        $payment = DB::table('angsuran')->where('id', $installment)->where('pinjaman_id', $loan)->first();
        abort_unless($payment, 404);

        return $this->pdf('pdf.payment-receipt', ['loan' => $data['loan'], 'payment' => $payment, 'cooperative' => $data['cooperative']], 'bukti-bayar-'.$data['loan']->kode_pinjaman.'-'.$installment.'.pdf');
    }

    public function settlementReceipt(Request $request, string $role, int $loan): SymfonyResponse
    {
        $this->authorizeLoan($request, $role, $loan);
        $data = $this->loanBundle($loan);
        $settlement = Schema::hasTable('layanan_pembayaran')
            ? DB::table('layanan_pembayaran')->where('pinjaman_id', $loan)->where('jenis', 'pelunasan')->whereNull('dibatalkan_at')->orderByDesc('id')->first()
            : null;

        return $this->pdf('pdf.settlement-receipt', ['loan' => $data['loan'], 'settlement' => $settlement, 'cooperative' => $data['cooperative']], 'bukti-lunas-'.$data['loan']->kode_pinjaman.'.pdf');
    }

    public function shuReceipt(Request $request, string $role, int $entitlement): SymfonyResponse
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

        return $this->pdf('pdf.shu-receipt', compact('row', 'cooperative'), 'kwitansi-shu-'.$row->tahun.'-'.$row->kode_snapshot.'.pdf');
    }

    public function report(Request $request, string $role): SymfonyResponse
    {
        $this->authorize('view-operational-reports');
        $jenis = (string) $request->query('jenis', 'simpanan');
        $rows = match ($jenis) {
            'kas' => DB::table('kas')->orderByDesc('id')->limit(500)->get(),
            'pinjaman' => DB::table('pinjaman as p')->join('anggota as a', 'a.id', '=', 'p.anggota_id')->orderByDesc('p.id')->limit(500)->get(['p.kode_pinjaman', 'a.nama_lengkap', 'p.status', 'p.jumlah_pinjaman', 'p.sisa_pokok']),
            'angsuran' => DB::table('angsuran as i')->join('pinjaman as p', 'p.id', '=', 'i.pinjaman_id')->orderByDesc('i.id')->limit(500)->get(['p.kode_pinjaman', 'i.angsuran_ke', 'i.status', 'i.jumlah_angsuran', 'i.tanggal_jatuh_tempo']),
            'harian' => DB::table('transaksi as t')->join('anggota as a', 'a.id', '=', 't.anggota_id')->whereDate('t.tanggal_transaksi', today()->toDateString())->orderByDesc('t.id')->limit(500)->get(['t.kode_transaksi', 'a.nama_lengkap', 't.jenis_transaksi', 't.jumlah', 't.tanggal_transaksi']),
            default => DB::table('transaksi as t')->join('anggota as a', 'a.id', '=', 't.anggota_id')->orderByDesc('t.id')->limit(500)->get(['t.kode_transaksi', 'a.nama_lengkap', 't.jenis_transaksi', 't.jumlah', 't.tanggal_transaksi']),
        };
        $cooperative = DB::table('koperasi')->orderBy('id')->first();

        return $this->pdf('pdf.report', compact('jenis', 'rows', 'cooperative'), 'laporan-'.$jenis.'-'.now()->format('Ymd').'.pdf');
    }

    public function draftAgreement(Request $request, string $role, int $application): SymfonyResponse
    {
        $this->authorize('manage-loan-applications');
        $row = DB::table('pengajuan_pinjaman as pp')
            ->join('jenis_pinjaman as jp', 'jp.id', '=', 'pp.jenis_pinjaman_id')
            ->join('anggota as a', 'a.id', '=', 'pp.anggota_id')
            ->leftJoin('jaminan_pinjaman as j', 'j.pengajuan_id', '=', 'pp.id')
            ->where('pp.id', $application)
            ->whereIn('pp.status', ['disetujui', 'dicairkan'])
            ->select(['pp.*', 'jp.nama_jenis', 'jp.memerlukan_jaminan', 'a.nama_lengkap', 'a.kode_anggota', 'a.ktp', 'a.alamat', 'a.telepon', 'j.jenis_barang', 'j.identitas_barang', 'j.deskripsi_barang'])
            ->first();
        abort_unless($row, 404);
        $cooperative = DB::table('koperasi')->orderBy('id')->first();

        return $this->pdf('pdf.draft-agreement', ['application' => $row, 'cooperative' => $cooperative], 'draft-perjanjian-'.$row->kode_pengajuan.'.pdf');
    }

    public function bast(Request $request, string $role, int $loan): SymfonyResponse
    {
        $this->authorizeLoan($request, $role, $loan);
        abort_unless(Schema::hasTable('layanan_jaminan_kembali'), 404);
        $data = $this->loanBundle($loan);
        $bast = DB::table('layanan_jaminan_kembali')->where('pinjaman_id', $loan)->where('status', 'diserahkan')->orderByDesc('id')->first();
        abort_unless($bast, 404);
        $collateral = Schema::hasTable('jaminan_pinjaman')
            ? DB::table('jaminan_pinjaman')->where('pinjaman_id', $loan)->first()
            : null;
        $template = Schema::hasTable('pengaturan_perjanjian')
            ? (string) (DB::table('pengaturan_perjanjian')->where('id', 1)->value('bast_jaminan') ?? '')
            : '';

        return $this->pdf('pdf.bast', [
            'loan' => $data['loan'],
            'bast' => $bast,
            'collateral' => $collateral,
            'template' => $template,
            'cooperative' => $data['cooperative'],
        ], 'bast-'.$data['loan']->kode_pinjaman.'.pdf');
    }

    public function shuPeriodReport(Request $request, string $role, int $period): SymfonyResponse
    {
        $this->authorize('manage-shu');
        abort_unless(Schema::hasTable('shu_periode'), 404);
        $detail = DB::table('shu_periode')->where('id', $period)->first();
        abort_unless($detail, 404);
        $members = DB::table('shu_anggota')->where('shu_periode_id', $period)->orderByDesc('total_shu')->get();
        $cooperative = DB::table('koperasi')->orderBy('id')->first();

        return $this->pdf('pdf.shu-period', compact('detail', 'members', 'cooperative'), 'laporan-shu-'.$detail->tahun.'.pdf');
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
            ->where('p.id', $loanId)
            ->select(['p.*', 'a.nama_lengkap', 'a.kode_anggota', 'a.alamat', 'a.telepon', 'jp.nama_jenis'])
            ->first();
        abort_unless($loan, 404);

        return [
            'loan' => $loan,
            'cooperative' => DB::table('koperasi')->orderBy('id')->first(),
        ];
    }

    /** @param  array<string, mixed>  $data */
    private function pdf(string $view, array $data, string $filename): Response
    {
        return Pdf::loadView($view, $data)->setPaper('a4')->download($filename);
    }
}
