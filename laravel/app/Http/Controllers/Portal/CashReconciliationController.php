<?php

namespace App\Http\Controllers\Portal;

use App\Actions\Audit\RecordActivity;
use App\Actions\Cash\RecalculateCashLedgerAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CashReconciliationController extends Controller
{
    public function index(string $role): View
    {
        $this->authorize('reconcile-cash');

        $rows = DB::table('kas')->orderBy('tanggal')->orderBy('id')->get(['id', 'tanggal', 'jenis', 'jumlah', 'saldo_sebelum', 'saldo_sesudah', 'keterangan']);
        $balance = 0.0;
        $mismatches = collect();

        foreach ($rows as $row) {
            $expectedBefore = $balance;
            $expectedAfter = $expectedBefore + ($row->jenis === 'masuk' ? (float) $row->jumlah : -(float) $row->jumlah);
            if (abs((float) $row->saldo_sebelum - $expectedBefore) > 0.004 || abs((float) $row->saldo_sesudah - $expectedAfter) > 0.004) {
                $mismatches->push((object) [
                    'id' => $row->id,
                    'tanggal' => $row->tanggal,
                    'keterangan' => $row->keterangan,
                    'stored_before' => (float) $row->saldo_sebelum,
                    'stored_after' => (float) $row->saldo_sesudah,
                    'expected_before' => $expectedBefore,
                    'expected_after' => $expectedAfter,
                ]);
            }
            $balance = $expectedAfter;
        }

        $totals = [
            'entries' => $rows->count(),
            'masuk' => (float) $rows->where('jenis', 'masuk')->sum('jumlah'),
            'keluar' => (float) $rows->where('jenis', 'keluar')->sum('jumlah'),
            'saldo' => $balance,
            'mismatches' => $mismatches,
        ];

        return view('portal.cash.reconciliation', compact('role', 'totals'));
    }

    public function rebuild(Request $request, string $role, RecalculateCashLedgerAction $recalculate, RecordActivity $audit): RedirectResponse
    {
        $this->authorize('reconcile-cash');
        $data = $request->validate(['alasan' => ['required', 'string', 'min:5', 'max:500']]);

        $result = $recalculate->handle();
        if ($result['entries'] === 0) {
            throw ValidationException::withMessages(['alasan' => 'Belum ada mutasi kas yang dapat disinkronkan.']);
        }

        $audit->handle($request->user(), 'cash.reconciled', 'kas', null, $result + ['alasan' => trim($data['alasan'])], $request);

        return redirect()->route('portal.cash.reconciliation.index', ['role' => $role])
            ->with('status', 'Saldo '.$result['entries'].' mutasi kas berhasil disinkronkan.');
    }
}
