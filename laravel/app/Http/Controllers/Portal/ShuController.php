<?php

namespace App\Http\Controllers\Portal;

use App\Actions\Audit\RecordActivity;
use App\Actions\Shu\PayLoanShuEntitlementAction;
use App\Actions\Shu\ProcessShuAction;
use App\Actions\Shu\ShuSupport;
use App\Actions\Shu\SyncLoanShuEntitlementsAction;
use App\Http\Controllers\Controller;
use App\Models\Anggota;
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

class ShuController extends Controller
{
    public function index(string $role, ShuSupport $support): View
    {
        $this->authorize('manage-shu');
        abort_unless($support->ready(), 503, 'Modul SHU memerlukan skema database terbaru.');
        $periods = DB::table('shu_periode')->orderByDesc('tahun')->get();
        $loanRights = Schema::hasTable('shu_hak_pinjaman')
            ? DB::table('shu_hak_pinjaman as h')->join('anggota as a', 'a.id', '=', 'h.anggota_id')->orderByDesc('h.id')->limit(50)->get(['h.*', 'a.nama_lengkap', 'a.kode_anggota'])
            : collect();

        return view('portal.shu.index', [
            'role' => $role,
            'periods' => $periods,
            'loanRights' => $loanRights,
            'categories' => ShuSupport::CATEGORIES,
        ]);
    }

    public function show(string $role, int $period, ShuSupport $support): View
    {
        $this->authorize('manage-shu');
        $detail = $support->period($period, false);
        $members = DB::table('shu_anggota')->where('shu_periode_id', $period)->orderByDesc('total_shu')->orderBy('nama_snapshot')->paginate(25)->withQueryString();
        $audit = DB::table('shu_audit as s')->leftJoin('users as u', 'u.id', '=', 's.user_id')->where('s.periode_id', $period)->orderByDesc('s.id')->limit(40)->get(['s.*', 'u.nama_lengkap as petugas']);
        $policy = json_decode((string) $detail->kebijakan_json, true) ?: [];
        $allocation = $detail->alokasi_json ? json_decode((string) $detail->alokasi_json, true) : [];

        return view('portal.shu.show', [
            'role' => $role,
            'period' => $detail,
            'members' => $members,
            'audit' => $audit,
            'policy' => $policy,
            'allocation' => $allocation,
            'categories' => ShuSupport::CATEGORIES,
            'paymentNonce' => bin2hex(random_bytes(16)),
        ]);
    }

    public function mine(string $role, ShuSupport $support): View
    {
        $this->authorize('view-own-shu');
        $user = request()->user();
        $memberId = (int) Anggota::query()->where('user_id', $user->id)->value('id');
        abort_unless($memberId > 0 || $user->role === 'admin', 404);

        $periodRows = $support->ready() && $memberId
            ? DB::table('shu_anggota as sa')->join('shu_periode as sp', 'sp.id', '=', 'sa.shu_periode_id')->where('sa.anggota_id', $memberId)->orderByDesc('sp.tahun')->get(['sa.*', 'sp.tahun', 'sp.nama_periode', 'sp.status as periode_status'])
            : collect();
        $loanRows = Schema::hasTable('shu_hak_pinjaman') && $memberId
            ? DB::table('shu_hak_pinjaman')->where('anggota_id', $memberId)->orderByDesc('id')->get()
            : collect();

        return view('portal.shu.mine', compact('role', 'periodRows', 'loanRows'));
    }

    public function process(Request $request, string $role, ProcessShuAction $action, ImageStorage $images, RecordActivity $audit): RedirectResponse
    {
        $this->authorize('manage-shu');
        $data = $request->validate([
            'aksi' => ['required', Rule::in(['buat', 'ubah', 'hitung', 'sahkan', 'bayar', 'balik'])],
            'periode_id' => ['nullable', 'integer'],
            'tahun' => ['nullable', 'integer'],
            'total_shu' => ['nullable', 'numeric'],
            'basis_simpanan' => ['nullable', Rule::in(['rata_harian', 'saldo_akhir'])],
            'referensi_rat' => ['nullable', 'string', 'max:200'],
            'konfirmasi_rat' => ['nullable'],
            'shu_anggota_id' => ['nullable', 'integer'],
            'metode' => ['nullable', Rule::in(['cash', 'transfer'])],
            'referensi' => ['nullable', 'string', 'max:100'],
            'payment_nonce' => ['nullable', 'string', 'size:32'],
            'pembayaran_id' => ['nullable', 'integer'],
            'alasan' => ['nullable', 'string', 'max:500'],
            'uang_kembali' => ['nullable'],
            'bukti' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.ImageLimits::SOURCE_MAX_KILOBYTES],
        ] + collect(array_keys(ShuSupport::CATEGORIES))->mapWithKeys(fn ($key) => ['persen_'.$key => ['nullable', 'numeric']])->all());

        $input = $data;
        if ($request->hasFile('bukti')) {
            $input['bukti'] = $images->store($request->file('bukti'), 'shu/bukti');
        }
        try {
            $periodId = $action->handle($data['aksi'], $input, $request->user());
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['aksi' => $exception->getMessage()]);
        }
        $audit->handle($request->user(), 'shu.'.$data['aksi'], 'shu_periode', $periodId, ['aksi' => $data['aksi']], $request);

        return redirect()->route('portal.shu.show', ['role' => $role, 'period' => $periodId])
            ->with('status', 'Tindakan SHU berhasil: '.$data['aksi'].'.');
    }

    public function syncLoan(Request $request, string $role, SyncLoanShuEntitlementsAction $action, RecordActivity $audit): RedirectResponse
    {
        $this->authorize('manage-shu');
        try {
            $count = $action->handle(null, $request->user());
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['aksi' => $exception->getMessage()]);
        }
        $audit->handle($request->user(), 'shu.sync_loan', 'shu_hak_pinjaman', null, ['count' => $count], $request);

        return back()->with('status', 'Sinkronisasi selesai. '.$count.' pinjaman lunas diperiksa.');
    }

    public function payLoan(Request $request, string $role, PayLoanShuEntitlementAction $action, ImageStorage $images, RecordActivity $audit): RedirectResponse
    {
        $this->authorize('manage-shu');
        $data = $request->validate([
            'hak_id' => ['required', 'integer'],
            'metode' => ['required', Rule::in(['cash', 'transfer'])],
            'referensi' => ['required', 'string', 'min:3', 'max:100'],
            'bukti' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.ImageLimits::SOURCE_MAX_KILOBYTES],
        ]);
        $proof = $images->store($request->file('bukti'), 'shu/bukti');
        try {
            $txId = $action->handle((int) $data['hak_id'], $data['metode'], $data['referensi'], $proof, $request->user());
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['hak_id' => $exception->getMessage()]);
        }
        $audit->handle($request->user(), 'shu.pay_loan', 'shu_hak_pinjaman', (int) $data['hak_id'], ['transaksi_id' => $txId], $request);

        return back()->with('status', 'Pembayaran SHU pinjaman berhasil dicatat.');
    }
}
