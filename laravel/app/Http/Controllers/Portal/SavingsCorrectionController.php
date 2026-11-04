<?php

namespace App\Http\Controllers\Portal;

use App\Actions\Audit\RecordActivity;
use App\Actions\Savings\CorrectSavingsTransactionAction;
use App\Http\Controllers\Controller;
use App\Models\Transaksi;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SavingsCorrectionController extends Controller
{
    public function index(Request $request, string $role): View
    {
        $this->authorize('manage-savings-corrections');

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'jenis' => ['nullable', Rule::in(['setoran', 'penarikan'])],
            'per_page' => ['nullable', Rule::in([5, 10, 25, 50])],
        ]);

        $transactions = Transaksi::query()
            ->with(['member:id,kode_anggota,nama_lengkap', 'savingType:id,kode_jenis,nama_jenis', 'actor:id,nama_lengkap'])
            ->whereIn('jenis_transaksi', ['setoran', 'penarikan'])
            ->whereHas('savingType', fn ($types) => $types->whereRaw('UPPER(kode_jenis) <> ?', ['SHU']))
            ->when(filled($filters['q'] ?? null), function ($query) use ($filters): void {
                $term = '%'.$filters['q'].'%';
                $query->where(function ($builder) use ($term): void {
                    $builder->where('kode_transaksi', 'like', $term)
                        ->orWhereHas('member', fn ($members) => $members->where('nama_lengkap', 'like', $term)->orWhere('kode_anggota', 'like', $term));
                });
            })
            ->when(filled($filters['jenis'] ?? null), fn ($query) => $query->where('jenis_transaksi', $filters['jenis']))
            ->orderByDesc('tanggal_transaksi')
            ->paginate((int) ($filters['per_page'] ?? 10))
            ->withQueryString();

        $logs = DB::table('log_koreksi as l')
            ->join('transaksi as t', 't.id', '=', 'l.transaksi_id')
            ->join('anggota as a', 'a.id', '=', 't.anggota_id')
            ->join('users as u', 'u.id', '=', 'l.user_id')
            ->orderByDesc('l.created_at')
            ->limit(10)
            ->get(['l.*', 't.kode_transaksi', 'a.nama_lengkap as anggota_nama', 'u.nama_lengkap as petugas_nama']);

        return view('portal.savings.corrections', compact('role', 'filters', 'transactions', 'logs'));
    }

    public function store(Request $request, string $role, Transaksi $transaction, CorrectSavingsTransactionAction $correct, RecordActivity $audit): RedirectResponse
    {
        $this->authorize('manage-savings-corrections');

        $data = $request->validate([
            'jumlah_baru' => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'keterangan_koreksi' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        try {
            $snapshot = $correct->handle($transaction, (float) $data['jumlah_baru'], trim($data['keterangan_koreksi']), $request->user());
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['jumlah_baru' => $exception->getMessage()]);
        }

        $audit->handle($request->user(), 'savings.corrected', Transaksi::class, $transaction->id, $snapshot, $request);

        return redirect()->route('portal.savings.corrections.index', ['role' => $role])
            ->with('status', 'Koreksi '.$snapshot['kode_transaksi'].' berhasil disimpan dan saldo dihitung ulang.');
    }
}
