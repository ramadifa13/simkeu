<?php

namespace App\Http\Controllers\Portal;

use App\Actions\Audit\RecordActivity;
use App\Actions\Layanan\DecideDelayDisbursementAction;
use App\Actions\Layanan\LayananSupport;
use App\Actions\Layanan\ProcessLayananAction;
use App\Actions\Layanan\RequestDelayDisbursementAction;
use App\Http\Controllers\Controller;
use App\Support\ImageLimits;
use App\Support\ImageStorage;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LayananController extends Controller
{
    public function index(Request $request, string $role): View
    {
        $this->authorize('manage-layanan');
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['aktif', 'lunas', 'macet'])],
            'focus' => ['nullable', Rule::in(['macet', 'hapus_denda', 'prioritas', 'tunda_cair', 'lelang'])],
            'per_page' => ['nullable', Rule::in([5, 10, 25, 50])],
        ]);

        $query = DB::table('pinjaman as p')
            ->join('anggota as a', 'a.id', '=', 'p.anggota_id')
            ->join('jenis_pinjaman as jp', 'jp.id', '=', 'p.jenis_pinjaman_id')
            ->select(['p.id', 'p.kode_pinjaman', 'p.status', 'p.jumlah_pinjaman', 'p.sisa_pokok', 'p.jatuh_tempo', 'a.nama_lengkap', 'a.kode_anggota', 'jp.nama_jenis', 'jp.memerlukan_jaminan']);

        if (filled($filters['q'] ?? null)) {
            $term = '%'.$filters['q'].'%';
            $query->where(function ($builder) use ($term): void {
                $builder->where('p.kode_pinjaman', 'like', $term)
                    ->orWhere('a.nama_lengkap', 'like', $term)
                    ->orWhere('a.kode_anggota', 'like', $term);
            });
        }
        if (($filters['status'] ?? '') === 'macet') {
            $threshold = today()->subDays(\App\Support\LoanLifecycle::overdueDaysForMacet())->toDateString();
            $query->where(function ($builder) use ($threshold): void {
                $builder->where('p.status', 'macet')
                    ->orWhere(function ($overdue) use ($threshold): void {
                        $overdue->whereIn('p.status', \App\Support\LoanLifecycle::collectibleStatuses())
                            ->whereExists(fn ($q) => $q->selectRaw('1')->from('angsuran as i')
                                ->whereColumn('i.pinjaman_id', 'p.id')
                                ->where('i.status', '<>', 'lunas')
                                ->whereDate('i.tanggal_jatuh_tempo', '<=', $threshold));
                    });
            });
        } elseif (filled($filters['status'] ?? null)) {
            $query->where('p.status', $filters['status']);
        }

        if (($filters['focus'] ?? '') === 'macet') {
            $query->whereExists(fn ($q) => $q->selectRaw('1')->from('layanan_laporan_macet as lm')
                ->whereColumn('lm.pinjaman_id', 'p.id')->where('lm.status', 'menunggu'));
        } elseif (($filters['focus'] ?? '') === 'hapus_denda') {
            $query->whereExists(fn ($q) => $q->selectRaw('1')->from('layanan_pengajuan_hapus_denda as hd')
                ->whereColumn('hd.pinjaman_id', 'p.id')->where('hd.status', 'menunggu'));
        } elseif (($filters['focus'] ?? '') === 'prioritas') {
            $query->whereExists(fn ($q) => $q->selectRaw('1')->from('layanan_prioritas as lp')
                ->whereColumn('lp.pinjaman_id', 'p.id')->whereIn('lp.status', ['menunggu', 'pengecekan']));
        } elseif (($filters['focus'] ?? '') === 'lelang') {
            $query->whereExists(fn ($q) => $q->selectRaw('1')->from('layanan_lelang as ll')->whereColumn('ll.pinjaman_id', 'p.id'));
        }

        $loans = $query->orderByDesc('p.id')->paginate((int) ($filters['per_page'] ?? 10))->withQueryString();

        $queues = [
            'macet' => Schema::hasTable('layanan_laporan_macet') ? (int) DB::table('layanan_laporan_macet')->where('status', 'menunggu')->count() : 0,
            'hapus_denda' => Schema::hasTable('layanan_pengajuan_hapus_denda') ? (int) DB::table('layanan_pengajuan_hapus_denda')->where('status', 'menunggu')->count() : 0,
            'prioritas' => Schema::hasTable('layanan_prioritas') ? (int) DB::table('layanan_prioritas')->whereIn('status', ['menunggu', 'pengecekan'])->count() : 0,
            'tunda_cair' => Schema::hasTable('layanan_tunda_cair') ? (int) DB::table('layanan_tunda_cair')->where('status', 'menunggu')->count() : 0,
        ];

        $delayRequests = Schema::hasTable('layanan_tunda_cair')
            ? DB::table('layanan_tunda_cair as tc')
                ->join('pengajuan_pinjaman as pp', 'pp.id', '=', 'tc.pengajuan_id')
                ->join('anggota as a', 'a.id', '=', 'pp.anggota_id')
                ->where('tc.status', 'menunggu')
                ->orderByDesc('tc.id')
                ->limit(20)
                ->get(['tc.id', 'tc.alasan', 'tc.created_at', 'pp.id as pengajuan_id', 'pp.kode_pengajuan', 'a.nama_lengkap', 'a.kode_anggota'])
            : collect();

        return view('portal.layanan.index', [
            'role' => $role,
            'loans' => $loans,
            'filters' => $filters,
            'queues' => $queues,
            'delayRequests' => $delayRequests,
        ]);
    }

    public function show(string $role, int $loan, LayananSupport $support): View
    {
        $this->authorize('manage-layanan');
        $detail = $support->loan($loan, false);
        $installments = DB::table('angsuran')->where('pinjaman_id', $loan)->orderBy('angsuran_ke')->get();
        $remaining = $support->remainingDebt($loan);
        $audit = Schema::hasTable('layanan_audit')
            ? DB::table('layanan_audit as la')->leftJoin('users as u', 'u.id', '=', 'la.user_id')->where('la.pinjaman_id', $loan)->orderByDesc('la.id')->limit(50)->get(['la.*', 'u.nama_lengkap as petugas'])
            : collect();
        $macet = Schema::hasTable('layanan_laporan_macet') ? DB::table('layanan_laporan_macet')->where('pinjaman_id', $loan)->orderByDesc('id')->get() : collect();
        $writeOffs = Schema::hasTable('layanan_pengajuan_hapus_denda') ? DB::table('layanan_pengajuan_hapus_denda')->where('pinjaman_id', $loan)->orderByDesc('id')->get() : collect();
        $priority = Schema::hasTable('layanan_prioritas') ? DB::table('layanan_prioritas')->where('pinjaman_id', $loan)->first() : null;
        $auction = Schema::hasTable('layanan_lelang') ? DB::table('layanan_lelang')->where('pinjaman_id', $loan)->first() : null;
        $collateral = Schema::hasTable('jaminan_pinjaman') ? DB::table('jaminan_pinjaman')->where('pinjaman_id', $loan)->first() : null;
        $bonus = Schema::hasTable('layanan_bonus') ? DB::table('layanan_bonus')->where('pinjaman_id', $loan)->first() : null;
        $repeat = Schema::hasTable('layanan_repeat_order') ? DB::table('layanan_repeat_order')->where('pinjaman_id', $loan)->first() : null;
        $pendingPenaltyPayments = Schema::hasTable('layanan_bayar_denda') && Schema::hasColumn('layanan_bayar_denda', 'approved_by')
            ? DB::table('layanan_bayar_denda')->where('pinjaman_id', $loan)->whereNull('approved_by')->orderByDesc('id')->get()
            : collect();
        $penaltyReceipts = Schema::hasTable('layanan_bayar_denda')
            ? DB::table('layanan_bayar_denda')->where('pinjaman_id', $loan)->orderByDesc('id')->limit(20)->get()
            : collect();
        $paymentReceipts = Schema::hasTable('layanan_pembayaran')
            ? DB::table('layanan_pembayaran')->where('pinjaman_id', $loan)->whereNull('dibatalkan_at')->orderByDesc('id')->limit(20)->get()
            : collect();
        $auctionReceipts = Schema::hasTable('layanan_lelang')
            ? DB::table('layanan_lelang')->where('pinjaman_id', $loan)->orderByDesc('id')->get()
            : collect();
        $bonusReceipts = Schema::hasTable('layanan_bonus')
            ? DB::table('layanan_bonus')->where('pinjaman_id', $loan)->orderByDesc('id')->get()
            : collect();
        $bastReady = Schema::hasTable('layanan_jaminan_kembali')
            && DB::table('layanan_jaminan_kembali')->where('pinjaman_id', $loan)->where('status', 'diserahkan')->exists();

        return view('portal.layanan.show', [
            'role' => $role,
            'loan' => $detail,
            'installments' => $installments,
            'remaining' => $remaining,
            'audit' => $audit,
            'macet' => $macet,
            'writeOffs' => $writeOffs,
            'priority' => $priority,
            'auction' => $auction,
            'collateral' => $collateral,
            'bonus' => $bonus,
            'repeat' => $repeat,
            'pendingPenaltyPayments' => $pendingPenaltyPayments,
            'penaltyReceipts' => $penaltyReceipts,
            'paymentReceipts' => $paymentReceipts,
            'auctionReceipts' => $auctionReceipts,
            'bonusReceipts' => $bonusReceipts,
            'bastReady' => $bastReady,
            'pemutihan' => $support->pemutihanEnabled(),
            'priorityEligible' => $support->priorityEligible((int) $detail->anggota_id),
        ]);
    }

    public function process(Request $request, string $role, int $loan, ProcessLayananAction $action, ImageStorage $images, RecordActivity $audit): RedirectResponse
    {
        $this->authorize('manage-layanan');
        $data = $request->validate([
            'aksi' => ['required', Rule::in([
                'lapor_macet', 'keputusan_macet', 'reschedule', 'eksekusi', 'diskon_denda', 'approve_denda',
                'ajukan_hapus_denda', 'putuskan_hapus_denda', 'prioritas', 'koreksi_prioritas', 'approve_prioritas',
                'tolak_prioritas', 'jual_jaminan', 'kembalikan_lebih', 'bayar_bonus', 'repeat_order',
            ])],
            'catatan' => ['nullable', 'string', 'max:2000'],
            'keputusan' => ['nullable', 'string', 'max:40'],
            'tanggal' => ['nullable', 'date'],
            'jumlah' => ['nullable', 'numeric', 'min:0'],
            'metode' => ['nullable', Rule::in(['cash', 'transfer'])],
            'angsuran_id' => ['nullable', 'integer'],
            'laporan_id' => ['nullable', 'integer'],
            'pengajuan_id' => ['nullable', 'integer'],
            'bayar_id' => ['nullable', 'integer'],
            'bukti' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.ImageLimits::SOURCE_MAX_KILOBYTES],
        ]);

        $adminOnly = ['keputusan_macet', 'reschedule', 'eksekusi', 'diskon_denda', 'approve_denda', 'putuskan_hapus_denda', 'koreksi_prioritas', 'approve_prioritas', 'tolak_prioritas', 'jual_jaminan', 'kembalikan_lebih', 'bayar_bonus', 'repeat_order'];
        if (in_array($data['aksi'], $adminOnly, true)) {
            $this->authorize('decide-layanan');
        }

        $input = $data;
        if ($request->hasFile('bukti')) {
            $input['bukti'] = $images->store($request->file('bukti'), 'layanan/bukti');
        }

        try {
            $detail = $action->handle($loan, $data['aksi'], $input, $request->user());
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['aksi' => $exception->getMessage()]);
        }

        $audit->handle($request->user(), 'layanan.'.$data['aksi'], 'pinjaman', $loan, ['detail' => $detail], $request);

        if ($data['aksi'] === 'repeat_order') {
            $anggotaId = (int) DB::table('pinjaman')->where('id', $loan)->value('anggota_id');

            return redirect()
                ->route('portal.loans.applications.create', ['role' => $role, 'anggota_id' => $anggotaId])
                ->with('status', 'Repeat order dicatat. Lanjutkan buat pengajuan pinjaman baru untuk anggota ini.');
        }

        return redirect()->route('portal.layanan.show', ['role' => $role, 'loan' => $loan])
            ->with('status', 'Tindakan layanan berhasil: '.$data['aksi'].'.');
    }

    public function requestDelay(Request $request, string $role, RequestDelayDisbursementAction $action, RecordActivity $audit): RedirectResponse
    {
        $this->authorize('manage-layanan');
        $data = $request->validate([
            'pengajuan_id' => ['required', 'integer'],
            'alasan' => ['required', 'string', 'min:5', 'max:2000'],
        ]);
        try {
            $detail = $action->handle((int) $data['pengajuan_id'], $data['alasan'], $request->user());
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['alasan' => $exception->getMessage()]);
        }
        $audit->handle($request->user(), 'layanan.ajukan_tunda_cair', 'pengajuan_pinjaman', (int) $data['pengajuan_id'], ['detail' => $detail], $request);

        return redirect()->route('portal.layanan.index', ['role' => $role, 'focus' => 'tunda_cair'])
            ->with('status', 'Pengajuan TC dikirim dan menunggu keputusan Admin.');
    }

    public function decideDelay(Request $request, string $role, DecideDelayDisbursementAction $action, RecordActivity $audit): RedirectResponse
    {
        $this->authorize('decide-delay-disbursement');
        $data = $request->validate([
            'tc_id' => ['required', 'integer'],
            'keputusan' => ['required', Rule::in(['disetujui', 'ditolak'])],
            'catatan' => ['required', 'string', 'min:5', 'max:2000'],
        ]);
        try {
            $detail = $action->handle((int) $data['tc_id'], $data['keputusan'], $data['catatan'], $request->user());
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['catatan' => $exception->getMessage()]);
        }
        $audit->handle($request->user(), 'layanan.keputusan_tunda_cair', 'layanan_tunda_cair', (int) $data['tc_id'], ['keputusan' => $data['keputusan'], 'detail' => $detail], $request);

        return redirect()->route('portal.layanan.index', ['role' => $role, 'focus' => 'tunda_cair'])
            ->with('status', 'Keputusan TC tersimpan: '.$data['keputusan'].'.');
    }
}
