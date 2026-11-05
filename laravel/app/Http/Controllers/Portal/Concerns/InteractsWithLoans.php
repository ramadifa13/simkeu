<?php

namespace App\Http\Controllers\Portal\Concerns;

use App\Models\Anggota;
use App\Models\Pinjaman;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

trait InteractsWithLoans
{
    private function loanForRole(Request $request, string $role, int $loan): object
    {
        $model = Pinjaman::query()
            ->with([
                'anggota:id,kode_anggota,nama_lengkap',
                'jenisPinjaman:id,nama_jenis,memerlukan_jaminan',
            ])
            ->find($loan);
        abort_unless($model, 404);

        if ($role === 'anggota') {
            $memberId = Anggota::query()->where('user_id', $request->user()->id)->value('id');
            abort_unless($memberId && (int) $model->anggota_id === (int) $memberId, 404);
        }

        abort_unless($request->user()?->can('view', $model), 403);

        $result = (object) $model->getAttributes();
        $result->kode_anggota = $model->anggota?->kode_anggota;
        $result->nama_lengkap = $model->anggota?->nama_lengkap;
        $result->nama_jenis = $model->jenisPinjaman?->nama_jenis;
        $result->memerlukan_jaminan = $model->jenisPinjaman?->memerlukan_jaminan;

        return $result;
    }

    private function assertInstallmentMonthOpen(string $date): void
    {
        if (! Schema::hasTable('closing_angsuran_bulanan')) {
            return;
        }
        $month = Carbon::parse($date)->format('Y-m');
        if (DB::table('closing_angsuran_bulanan')->where('bulan', $month)->where('status', 'terkunci')->exists()) {
            throw ValidationException::withMessages(['tanggal_bayar' => 'Periode angsuran '.$month.' sudah dikunci.']);
        }
    }

    /** @return array{gross:float,discount_percent:float,discount_amount:float,total:float,penalty:float,request_status:?string} */
    private function settlementSummary(int $loan): array
    {
        $query = DB::table('angsuran as i')->where('i.pinjaman_id', $loan)->where('i.status', '<>', 'lunas')->select('i.*');
        if (Schema::hasTable('layanan_bayar_denda')) {
            $query->selectSub(
                DB::table('layanan_bayar_denda')->selectRaw('COALESCE(SUM(jumlah), 0)')->whereColumn('angsuran_id', 'i.id'),
                'denda_dibayar'
            );
        }
        $rows = $query->get();
        if (! Schema::hasTable('layanan_bayar_denda')) {
            $rows->each(fn (object $row) => $row->denda_dibayar = 0);
        }
        $discount = 0.0;
        $requestStatus = null;
        if (Schema::hasTable('layanan_pelunasan')) {
            $settlementRequest = DB::table('layanan_pelunasan')->where('pinjaman_id', $loan)->first(['status', 'diskon_persen']);
            $requestStatus = $settlementRequest?->status;
            $discount = $requestStatus === 'disetujui' ? (float) $settlementRequest->diskon_persen : 0.0;
        }
        $gross = (float) $rows->sum('jumlah_angsuran');
        $discountAmount = round((float) $rows->sum('bunga') * $discount / 100, 2);

        return ['gross' => $gross, 'discount_percent' => $discount, 'discount_amount' => $discountAmount, 'total' => max(0, $gross - $discountAmount), 'penalty' => (float) $rows->sum(fn ($row) => max(0, (float) $row->denda - (float) $row->denda_dibayar)), 'request_status' => $requestStatus];
    }
}
