<?php

namespace App\Http\Controllers\Portal;

use App\Actions\Audit\RecordActivity;
use App\Http\Controllers\Controller;
use App\Models\DendaTarif;
use App\Models\JenisPinjaman;
use App\Models\JenisPinjamanTarif;
use App\Models\JenisSimpanan;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class MasterDataController extends Controller
{
    /** @var list<string> */
    private const RESERVED_SAVING_CODES = ['ANG', 'BONUS', 'SHU'];

    public function index(Request $request, string $role): View
    {
        $this->authorize('manage-master-data');

        $tab = $request->string('tab')->toString();
        $tab = in_array($tab, ['simpanan', 'pinjaman', 'tarif', 'denda'], true) ? $tab : 'simpanan';

        return view('portal.master.index', [
            'role' => $role,
            'tab' => $tab,
            'savings' => JenisSimpanan::query()->orderBy('nama_jenis')->get(),
            'loans' => JenisPinjaman::query()->orderBy('nama_jenis')->get(),
            'loanRates' => JenisPinjamanTarif::query()->with('loan:id,nama_jenis,kode_jenis')->orderBy('jenis_pinjaman_id')->orderBy('tenor_bulan')->get(),
            'penaltyRates' => DendaTarif::query()->orderBy('jumlah_pinjaman')->get(),
        ]);
    }

    public function createSaving(string $role): View
    {
        $this->authorize('manage-master-data');

        return view('portal.master.savings.create', ['role' => $role]);
    }

    public function storeSaving(Request $request, string $role, RecordActivity $audit): RedirectResponse
    {
        $this->authorize('manage-master-data');
        $data = $request->validate($this->savingRules());
        $this->guardReservedSavingCode($data['kode_jenis']);

        $item = JenisSimpanan::query()->create($data);
        $audit->handle($request->user(), 'saving_type.created', JenisSimpanan::class, $item->id, ['kode' => $item->kode_jenis], $request);

        return redirect()
            ->route('portal.master.index', ['role' => $role, 'tab' => 'simpanan'])
            ->with('status', 'Jenis simpanan ditambahkan.');
    }

    public function editSaving(string $role, JenisSimpanan $saving): View
    {
        $this->authorize('manage-master-data');

        return view('portal.master.savings.edit', [
            'role' => $role,
            'saving' => $saving,
            'isReserved' => $this->isReservedSavingCode($saving->kode_jenis),
        ]);
    }

    public function updateSaving(Request $request, string $role, JenisSimpanan $saving, RecordActivity $audit): RedirectResponse
    {
        $this->authorize('manage-master-data');

        if ($this->isReservedSavingCode($saving->kode_jenis)) {
            throw ValidationException::withMessages([
                'kode_jenis' => 'Jenis simpanan sistem (ANG, BONUS, SHU) tidak dapat diubah dari master data.',
            ]);
        }

        $data = $request->validate($this->savingRules($saving));
        $this->guardReservedSavingCode($data['kode_jenis']);

        $saving->update($data);
        $audit->handle($request->user(), 'saving_type.updated', JenisSimpanan::class, $saving->id, ['kode' => $saving->kode_jenis], $request);

        return redirect()
            ->route('portal.master.index', ['role' => $role, 'tab' => 'simpanan'])
            ->with('status', 'Jenis simpanan diperbarui.');
    }

    public function createLoan(string $role): View
    {
        $this->authorize('manage-master-data');

        return view('portal.master.loans.create', ['role' => $role]);
    }

    public function destroySaving(Request $request, string $role, JenisSimpanan $saving, RecordActivity $audit): RedirectResponse
    {
        $this->authorize('manage-master-data');

        try {
            if ($this->isReservedSavingCode($saving->kode_jenis)) {
                throw new DomainException('Jenis simpanan sistem (ANG, BONUS, atau SHU) tidak dapat dihapus.');
            }

            if (DB::table('simpanan')->where('jenis_simpanan_id', $saving->id)->exists()
                || DB::table('transaksi')->where('jenis_simpanan_id', $saving->id)->exists()) {
                throw new DomainException('Jenis simpanan tidak dapat dihapus karena sudah memiliki rekening atau transaksi. Nonaktifkan saja bila tidak dipakai lagi.');
            }

            $id = $saving->id;
            $code = $saving->kode_jenis;
            $saving->delete();
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        } catch (QueryException) {
            return back()->with('error', 'Jenis simpanan tidak dapat dihapus karena masih terhubung dengan data operasional. Nonaktifkan saja bila tidak dipakai lagi.');
        }

        $audit->handle($request->user(), 'saving_type.deleted', JenisSimpanan::class, $id, ['kode' => $code], $request);

        return redirect()->route('portal.master.index', ['role' => $role, 'tab' => 'simpanan'])->with('status', 'Jenis simpanan dihapus.');
    }

    public function storeLoan(Request $request, string $role, RecordActivity $audit): RedirectResponse
    {
        $this->authorize('manage-master-data');
        $data = $request->validate($this->loanRules());
        $data['memerlukan_jaminan'] = $request->boolean('memerlukan_jaminan');
        $data['frekuensi_diizinkan'] = implode(',', $data['frekuensi_diizinkan']);

        $item = JenisPinjaman::query()->create($data);
        $audit->handle($request->user(), 'loan_type.created', JenisPinjaman::class, $item->id, ['kode' => $item->kode_jenis], $request);

        return redirect()
            ->route('portal.master.index', ['role' => $role, 'tab' => 'pinjaman'])
            ->with('status', 'Jenis pinjaman ditambahkan.');
    }

    public function editLoan(string $role, JenisPinjaman $loan): View
    {
        $this->authorize('manage-master-data');

        return view('portal.master.loans.edit', [
            'role' => $role,
            'loan' => $loan,
        ]);
    }

    public function updateLoan(Request $request, string $role, JenisPinjaman $loan, RecordActivity $audit): RedirectResponse
    {
        $this->authorize('manage-master-data');
        $data = $request->validate($this->loanRules($loan));
        $data['memerlukan_jaminan'] = $request->boolean('memerlukan_jaminan');
        $data['frekuensi_diizinkan'] = implode(',', $data['frekuensi_diizinkan']);

        $loan->update($data);
        $audit->handle($request->user(), 'loan_type.updated', JenisPinjaman::class, $loan->id, ['kode' => $loan->kode_jenis], $request);

        return redirect()
            ->route('portal.master.index', ['role' => $role, 'tab' => 'pinjaman'])
            ->with('status', 'Jenis pinjaman diperbarui.');
    }

    public function createLoanRate(string $role): View
    {
        $this->authorize('manage-master-data');

        return view('portal.master.loan-rates.create', [
            'role' => $role,
            'loans' => JenisPinjaman::query()->orderBy('nama_jenis')->get(['id', 'nama_jenis', 'kode_jenis']),
        ]);
    }

    public function destroyLoan(Request $request, string $role, JenisPinjaman $loan, RecordActivity $audit): RedirectResponse
    {
        $this->authorize('manage-master-data');

        try {
            if (DB::table('pengajuan_pinjaman')->where('jenis_pinjaman_id', $loan->id)->exists()
                || DB::table('pinjaman')->where('jenis_pinjaman_id', $loan->id)->exists()) {
                throw new DomainException('Jenis pinjaman tidak dapat dihapus karena sudah dipakai pada pengajuan atau pinjaman. Nonaktifkan saja bila tidak dipakai lagi.');
            }

            if (DB::table('jenis_pinjaman_tarif')->where('jenis_pinjaman_id', $loan->id)->exists()) {
                throw new DomainException('Hapus tarif bunga per tenor milik jenis pinjaman ini terlebih dahulu.');
            }

            $id = $loan->id;
            $code = $loan->kode_jenis;
            $loan->delete();
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        } catch (QueryException) {
            return back()->with('error', 'Jenis pinjaman tidak dapat dihapus karena masih terhubung dengan data operasional. Nonaktifkan saja bila tidak dipakai lagi.');
        }

        $audit->handle($request->user(), 'loan_type.deleted', JenisPinjaman::class, $id, ['kode' => $code], $request);

        return redirect()->route('portal.master.index', ['role' => $role, 'tab' => 'pinjaman'])->with('status', 'Jenis pinjaman dihapus.');
    }

    public function storeLoanRate(Request $request, string $role, RecordActivity $audit): RedirectResponse
    {
        $this->authorize('manage-master-data');
        $data = $request->validate($this->loanRateRules());
        $item = JenisPinjamanTarif::query()->create($data);
        $audit->handle($request->user(), 'loan_rate.created', JenisPinjamanTarif::class, $item->id, $data, $request);

        return redirect()
            ->route('portal.master.index', ['role' => $role, 'tab' => 'tarif'])
            ->with('status', 'Tarif tenor pinjaman ditambahkan.');
    }

    public function editLoanRate(string $role, JenisPinjamanTarif $rate): View
    {
        $this->authorize('manage-master-data');

        return view('portal.master.loan-rates.edit', [
            'role' => $role,
            'rate' => $rate,
            'loans' => JenisPinjaman::query()->orderBy('nama_jenis')->get(['id', 'nama_jenis', 'kode_jenis']),
        ]);
    }

    public function updateLoanRate(Request $request, string $role, JenisPinjamanTarif $rate, RecordActivity $audit): RedirectResponse
    {
        $this->authorize('manage-master-data');
        $data = $request->validate($this->loanRateRules());
        $rate->update($data);
        $audit->handle($request->user(), 'loan_rate.updated', JenisPinjamanTarif::class, $rate->id, $data, $request);

        return redirect()
            ->route('portal.master.index', ['role' => $role, 'tab' => 'tarif'])
            ->with('status', 'Tarif tenor pinjaman diperbarui.');
    }

    public function createPenaltyRate(string $role): View
    {
        $this->authorize('manage-master-data');

        return view('portal.master.penalty-rates.create', ['role' => $role]);
    }

    public function destroyLoanRate(Request $request, string $role, JenisPinjamanTarif $rate, RecordActivity $audit): RedirectResponse
    {
        $this->authorize('manage-master-data');

        try {
            $id = $rate->id;
            $context = ['jenis_pinjaman_id' => $rate->jenis_pinjaman_id, 'tenor_bulan' => $rate->tenor_bulan];
            $rate->delete();
        } catch (QueryException) {
            return back()->with('error', 'Tarif tenor tidak dapat dihapus karena masih terhubung dengan data operasional.');
        }
        $audit->handle($request->user(), 'loan_rate.deleted', JenisPinjamanTarif::class, $id, $context, $request);

        return redirect()->route('portal.master.index', ['role' => $role, 'tab' => 'tarif'])->with('status', 'Tarif tenor pinjaman dihapus.');
    }

    public function storePenaltyRate(Request $request, string $role, RecordActivity $audit): RedirectResponse
    {
        $this->authorize('manage-master-data');
        $data = $request->validate($this->penaltyRateRules());
        $item = DendaTarif::query()->create($data);
        $audit->handle($request->user(), 'penalty_rate.created', DendaTarif::class, $item->id, $data, $request);

        return redirect()
            ->route('portal.master.index', ['role' => $role, 'tab' => 'denda'])
            ->with('status', 'Tarif denda ditambahkan.');
    }

    public function editPenaltyRate(string $role, DendaTarif $rate): View
    {
        $this->authorize('manage-master-data');

        return view('portal.master.penalty-rates.edit', [
            'role' => $role,
            'rate' => $rate,
        ]);
    }

    public function updatePenaltyRate(Request $request, string $role, DendaTarif $rate, RecordActivity $audit): RedirectResponse
    {
        $this->authorize('manage-master-data');
        $data = $request->validate($this->penaltyRateRules($rate));
        $rate->update($data);
        $audit->handle($request->user(), 'penalty_rate.updated', DendaTarif::class, $rate->id, $data, $request);

        return redirect()
            ->route('portal.master.index', ['role' => $role, 'tab' => 'denda'])
            ->with('status', 'Tarif denda diperbarui.');
    }

    public function destroyPenaltyRate(Request $request, string $role, DendaTarif $rate, RecordActivity $audit): RedirectResponse
    {
        $this->authorize('manage-master-data');

        try {
            $id = $rate->id;
            $amount = $rate->jumlah_pinjaman;
            $rate->delete();
        } catch (QueryException) {
            return back()->with('error', 'Tarif denda tidak dapat dihapus karena masih terhubung dengan data operasional.');
        }
        $audit->handle($request->user(), 'penalty_rate.deleted', DendaTarif::class, $id, ['jumlah_pinjaman' => $amount], $request);

        return redirect()->route('portal.master.index', ['role' => $role, 'tab' => 'denda'])->with('status', 'Tarif denda dihapus.');
    }

    /**
     * @return array<string, mixed>
     */
    private function savingRules(?JenisSimpanan $item = null): array
    {
        return [
            'kode_jenis' => ['required', 'alpha_dash', 'max:10', Rule::unique('jenis_simpanan', 'kode_jenis')->ignore($item)],
            'nama_jenis' => ['required', 'string', 'max:50'],
            'deskripsi' => ['nullable', 'string'],
            'minimum' => ['nullable', 'numeric', 'min:0'],
            'maksimum' => ['nullable', 'numeric', 'gte:minimum'],
            'bagi_hasil' => ['nullable', 'numeric', 'between:0,100'],
            'status' => ['required', Rule::in(['aktif', 'nonaktif'])],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function loanRules(?JenisPinjaman $item = null): array
    {
        return [
            'kode_jenis' => ['required', 'alpha_dash', 'max:10', Rule::unique('jenis_pinjaman', 'kode_jenis')->ignore($item)],
            'nama_jenis' => ['required', 'string', 'max:50'],
            'maksimal_pinjaman' => ['required', 'numeric', 'min:0'],
            'tenor_max' => ['required', 'integer', 'min:1', 'max:360'],
            'bunga' => ['required', 'numeric', 'between:0,100'],
            'frekuensi_diizinkan' => ['required', 'array', 'min:1'],
            'frekuensi_diizinkan.*' => [Rule::in(['bulanan', 'mingguan', 'dua_mingguan'])],
            'deskripsi' => ['nullable', 'string'],
            'status' => ['required', Rule::in(['aktif', 'nonaktif'])],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function loanRateRules(): array
    {
        return [
            'jenis_pinjaman_id' => ['required', 'integer', Rule::exists('jenis_pinjaman', 'id')],
            'tenor_bulan' => ['required', 'integer', 'min:1', 'max:360'],
            'bunga' => ['required', 'numeric', 'between:0,100'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function penaltyRateRules(?DendaTarif $rate = null): array
    {
        return [
            'jumlah_pinjaman' => ['required', 'numeric', 'min:0', Rule::unique('denda_tarif', 'jumlah_pinjaman')->ignore($rate)],
            'denda_per_hari' => ['required', 'numeric', 'min:0'],
        ];
    }

    private function isReservedSavingCode(string $code): bool
    {
        return in_array(strtoupper($code), self::RESERVED_SAVING_CODES, true);
    }

    private function guardReservedSavingCode(string $code): void
    {
        if ($this->isReservedSavingCode($code)) {
            throw ValidationException::withMessages([
                'kode_jenis' => 'Kode ANG, BONUS, dan SHU dilindungi sistem dan tidak dapat dipakai di master data.',
            ]);
        }
    }
}
