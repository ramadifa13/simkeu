<?php

namespace App\Http\Controllers\Portal;

use App\Actions\Audit\RecordActivity;
use App\Actions\Loans\CancelApprovedLoanApplicationAction;
use App\Actions\Loans\DeleteLoanApplicationAction;
use App\Actions\Loans\DisburseLoanAction;
use App\Actions\Loans\ReviewLoanApplicationAction;
use App\Actions\Loans\SubmitLoanApplicationAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\DisburseLoanRequest;
use App\Http\Requests\ReviewLoanApplicationRequest;
use App\Http\Requests\StoreLoanApplicationRequest;
use App\Support\ImageStorage;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LoanApplicationController extends Controller
{
    public function index(Request $request, string $role): View
    {
        $this->authorize('manage-loan-applications');
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::in(['menunggu', 'disetujui', 'ditolak', 'dicairkan', 'batal_cair'])]]);
        $query = DB::table('pengajuan_pinjaman as pp')->join('anggota as a', 'a.id', '=', 'pp.anggota_id')->join('jenis_pinjaman as jp', 'jp.id', '=', 'pp.jenis_pinjaman_id')->select(['pp.*', 'a.kode_anggota', 'a.nama_lengkap', 'jp.nama_jenis']);
        if (filled($filters['q'] ?? null)) {
            $term = '%'.$filters['q'].'%';
            $query->where(fn ($q) => $q->where('pp.kode_pengajuan', 'like', $term)->orWhere('a.nama_lengkap', 'like', $term)->orWhere('a.kode_anggota', 'like', $term));
        }
        if (filled($filters['status'] ?? null)) {
            $query->where('pp.status', $filters['status']);
        }

        return view('portal.loans.applications.index', ['role' => $role, 'filters' => $filters, 'applications' => $query->orderByDesc('pp.tanggal_pengajuan')->paginate(10)->withQueryString()]);
    }

    public function show(string $role, int $application): View
    {
        $this->authorize('manage-loan-applications');
        $select = [
            'pp.*',
            'a.kode_anggota',
            'a.nama_lengkap',
            'a.telepon',
            'jp.nama_jenis',
            'jp.memerlukan_jaminan',
            'ketua.nama_lengkap as nama_ketua_kelompok',
            'pengaju.nama_lengkap as nama_pengaju',
            'admin.nama_lengkap as nama_admin',
        ];
        if (Schema::hasColumn('anggota', 'kode_anggota_jaminan')) {
            $select[] = 'a.kode_anggota_jaminan';
        }

        $application = DB::table('pengajuan_pinjaman as pp')
            ->join('anggota as a', 'a.id', '=', 'pp.anggota_id')
            ->join('jenis_pinjaman as jp', 'jp.id', '=', 'pp.jenis_pinjaman_id')
            ->leftJoin('anggota as ketua', 'ketua.id', '=', 'pp.ketua_kelompok_id')
            ->leftJoin('users as pengaju', 'pengaju.id', '=', 'pp.user_id')
            ->leftJoin('users as admin', 'admin.id', '=', 'pp.admin_id')
            ->where('pp.id', $application)
            ->first($select);
        abort_unless($application, 404);

        $collateral = Schema::hasTable('jaminan_pinjaman')
            ? DB::table('jaminan_pinjaman')->where('pengajuan_id', $application->id)->first()
            : null;
        $loan = DB::table('pinjaman')->where('pengajuan_id', $application->id)->first(['id', 'kode_pinjaman', 'status']);
        $delayRequest = Schema::hasTable('layanan_tunda_cair')
            ? DB::table('layanan_tunda_cair')->where('pengajuan_id', $application->id)->orderByDesc('id')->first()
            : null;

        return view('portal.loans.applications.show', compact('role', 'application', 'collateral', 'loan', 'delayRequest'));
    }

    public function create(string $role): View
    {
        $this->authorize('manage-loan-applications');

        $memberColumns = ['id', 'kode_anggota', 'nama_lengkap', 'kategori_shu', 'ketua_kelompok_id'];
        foreach (['kode_anggota_jaminan', 'foto_kk', 'foto_ktp_pasangan'] as $column) {
            if (Schema::hasColumn('anggota', $column)) {
                $memberColumns[] = $column;
            }
        }

        $products = DB::table('jenis_pinjaman')->where('status', 'aktif')->orderBy('nama_jenis')->get();
        $tariffs = Schema::hasTable('jenis_pinjaman_tarif')
            ? DB::table('jenis_pinjaman_tarif')->orderBy('tenor_bulan')->get(['jenis_pinjaman_id', 'tenor_bulan', 'bunga'])
            : collect();
        $products = $products->map(function (object $product) use ($tariffs): object {
            $product->tariffs = $tariffs->where('jenis_pinjaman_id', $product->id)
                ->mapWithKeys(fn ($row) => [(int) $row->tenor_bulan => (float) $row->bunga])
                ->all();

            return $product;
        })->filter(fn (object $product): bool => (bool) $product->memerlukan_jaminan || $product->tariffs !== [])->values();

        $memberColumns[] = 'status';

        $prefill = request()->validate([
            'anggota_id' => ['nullable', 'integer'],
        ]);

        return view('portal.loans.applications.create', [
            'role' => $role,
            'members' => DB::table('anggota')->whereIn('status', ['aktif', 'nonaktif'])->orderBy('nama_lengkap')->get($memberColumns),
            'groupLeaders' => DB::table('anggota')->where('status', 'aktif')->where('kategori_shu', 'ketua_kelompok')->orderBy('nama_lengkap')->get(['id', 'kode_anggota', 'nama_lengkap']),
            'products' => $products,
            'prefillMemberId' => $prefill['anggota_id'] ?? null,
        ]);
    }

    public function store(StoreLoanApplicationRequest $request, string $role, SubmitLoanApplicationAction $submit, RecordActivity $audit, ImageStorage $images): RedirectResponse
    {
        $data = $request->validated();
        $stored = [];
        try {
            if ($request->hasFile('foto_kk')) {
                $stored['foto_kk'] = $images->store($request->file('foto_kk'), 'anggota');
            }
            if ($request->hasFile('foto_ktp_pasangan')) {
                $stored['foto_ktp_pasangan'] = $images->store($request->file('foto_ktp_pasangan'), 'anggota');
            }
            if ($stored !== []) {
                $data['member_documents'] = $stored;
            }
            $id = $submit->handle($data, $request->user());
        } catch (DomainException $e) {
            foreach ($stored as $path) {
                Storage::disk('local')->delete($path);
            }
            throw ValidationException::withMessages(['pengajuan' => $e->getMessage()]);
        }
        $audit->handle($request->user(), 'loan.application.created', 'pengajuan_pinjaman', $id, ['anggota_id' => $data['anggota_id'], 'jenis_pinjaman_id' => $data['jenis_pinjaman_id']], $request);

        return redirect()->route('portal.loans.applications.index', ['role' => $role])->with('status', 'Pengajuan pinjaman berhasil dikirim.');
    }

    public function review(ReviewLoanApplicationRequest $request, string $role, int $application, ReviewLoanApplicationAction $review, RecordActivity $audit): RedirectResponse
    {
        $data = $request->validated();
        try {
            $review->handle($application, $data['decision'], $data['catatan_admin'] ?? null, $request->user());
        } catch (DomainException $e) {
            return back()->withErrors(['review' => $e->getMessage()]);
        }
        $audit->handle($request->user(), 'loan.application.reviewed', 'pengajuan_pinjaman', $application, ['decision' => $data['decision']], $request);

        return back()->with('status', 'Pengajuan berhasil '.($data['decision'] === 'disetujui' ? 'disetujui' : 'ditolak').'.');
    }

    public function disburse(DisburseLoanRequest $request, string $role, int $application, DisburseLoanAction $disburse, RecordActivity $audit): RedirectResponse
    {
        $applicationRow = DB::table('pengajuan_pinjaman as pp')->join('jenis_pinjaman as jp', 'jp.id', '=', 'pp.jenis_pinjaman_id')->where('pp.id', $application)->first(['pp.*', 'jp.memerlukan_jaminan']);
        abort_unless($applicationRow && $applicationRow->status === 'disetujui', 404);
        $data = $request->validated();
        $stored = [];

        try {
            $images = app(ImageStorage::class);
            $stored['foto_nasabah'] = $images->store($request->file('foto_nasabah'), 'pencairan');
            if ($applicationRow->memerlukan_jaminan) {
                $stored['foto_barang_jaminan'] = $images->store($request->file('foto_barang_jaminan'), 'jaminan');
                $stored['foto_nasabah_barang'] = $images->store($request->file('foto_nasabah_barang'), 'jaminan');
            }
            foreach (['ttd_nasabah', 'ttd_teller', 'ttd_saksi_1', 'ttd_saksi_2'] as $signature) {
                $stored[$signature] = $images->store($request->file($signature), 'perjanjian/tanda-tangan');
            }
            $attachments = [];
            foreach ($request->file('lampiran', []) as $file) {
                $attachments[] = $images->store($file, 'perjanjian/lampiran');
                $stored[] = end($attachments);
            }
            $adminSignature = DB::table('pengaturan_perjanjian')->where('id', 1)->value('ttd_admin');
            if (blank($adminSignature)) {
                throw new DomainException('Tanda tangan Admin pada Pengaturan Perjanjian belum tersedia.');
            }
            $loan = $disburse->handle($application, $data['tanggal_pencairan'], $request->user(), $stored, ['nama_saksi_1' => $data['nama_saksi_1'], 'nama_saksi_2' => $data['nama_saksi_2'], 'ttd_nasabah' => $stored['ttd_nasabah'], 'ttd_teller' => $stored['ttd_teller'], 'ttd_admin' => $adminSignature, 'ttd_saksi_1' => $stored['ttd_saksi_1'], 'ttd_saksi_2' => $stored['ttd_saksi_2'], 'lampiran' => $attachments], (int) ($data['sudah_dibayar'] ?? 0));
        } catch (\Throwable $exception) {
            foreach ($stored as $path) {
                Storage::disk('local')->delete($path);
            }

            return back()->withErrors(['pencairan' => $exception->getMessage()]);
        }

        $audit->handle($request->user(), 'loan.disbursed', 'pinjaman', $loan['loan_id'], [
            'pengajuan_id' => $application,
            'kode_pinjaman' => $loan['kode_pinjaman'],
            'tanggal_pencairan' => $data['tanggal_pencairan'], 'saksi_1' => $data['nama_saksi_1'], 'saksi_2' => $data['nama_saksi_2'],
        ], $request);

        return back()->with('status', "Pinjaman {$loan['kode_pinjaman']} berhasil dicairkan dan jadwal angsuran dibuat.");
    }

    public function disbursementForm(string $role, int $application): View
    {
        $this->authorize('disburse-loans');
        $application = DB::table('pengajuan_pinjaman as pp')->join('anggota as a', 'a.id', '=', 'pp.anggota_id')->join('jenis_pinjaman as jp', 'jp.id', '=', 'pp.jenis_pinjaman_id')->where('pp.id', $application)->where('pp.status', 'disetujui')->first(['pp.*', 'a.nama_lengkap', 'a.kode_anggota', 'jp.nama_jenis', 'jp.memerlukan_jaminan']);
        abort_unless($application, 404);

        return view('portal.loans.applications.disburse', ['role' => $role, 'application' => $application]);
    }

    public function cancel(Request $request, string $role, int $application, CancelApprovedLoanApplicationAction $cancel, RecordActivity $audit): RedirectResponse
    {
        $this->authorize('review-loan-applications');
        $data = $request->validate(['catatan_admin' => ['required', 'string', 'max:2000']]);
        try {
            $cancel->handle($application, $data['catatan_admin']);
        } catch (DomainException $exception) {
            return back()->withErrors(['pembatalan' => $exception->getMessage()]);
        }
        $audit->handle($request->user(), 'loan.application.cancelled', 'pengajuan_pinjaman', $application, ['reason' => $data['catatan_admin']], $request);

        return back()->with('status', 'Pencairan pengajuan dibatalkan.');
    }

    public function destroy(Request $request, string $role, int $application, DeleteLoanApplicationAction $delete, RecordActivity $audit): RedirectResponse
    {
        $this->authorize('delete-loan-applications');
        try {
            $delete->handle($application);
        } catch (DomainException $exception) {
            return back()->withErrors(['hapus' => $exception->getMessage()]);
        }
        $audit->handle($request->user(), 'loan.application.deleted', 'pengajuan_pinjaman', $application, [], $request);

        return redirect()
            ->route('portal.loans.applications.index', ['role' => $role])
            ->with('status', 'Pengajuan pinjaman berhasil dihapus.');
    }
}
