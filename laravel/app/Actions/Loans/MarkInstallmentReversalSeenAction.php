<?php

namespace App\Actions\Loans;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MarkInstallmentReversalSeenAction
{
    public function handle(User $teller, ?int $loanId = null): int
    {
        if ($teller->role !== 'teller' || ! Schema::hasTable('pengajuan_pembatalan_angsuran')) {
            return 0;
        }
        if (! Schema::hasColumn('pengajuan_pembatalan_angsuran', 'dilihat_teller')) {
            return 0;
        }

        $query = DB::table('pengajuan_pembatalan_angsuran as r')
            ->join('angsuran as a', 'a.id', '=', 'r.angsuran_id')
            ->where('r.teller_id', $teller->id)
            ->where('r.dilihat_teller', 0)
            ->where('r.status', '<>', 'menunggu');

        if ($loanId) {
            $query->where('a.pinjaman_id', $loanId);
        }

        $ids = $query->pluck('r.id');
        if ($ids->isEmpty()) {
            return 0;
        }

        return DB::table('pengajuan_pembatalan_angsuran')
            ->whereIn('id', $ids->all())
            ->update(['dilihat_teller' => 1]);
    }
}
