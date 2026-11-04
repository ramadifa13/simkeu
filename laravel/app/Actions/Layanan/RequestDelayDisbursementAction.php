<?php

namespace App\Actions\Layanan;

use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Staff request to delay disbursement of an approved application. */
class RequestDelayDisbursementAction
{
    public function __construct(private readonly LayananSupport $support) {}

    public function handle(int $applicationId, string $reason, User $actor): string
    {
        if (! Schema::hasTable('layanan_tunda_cair')) {
            throw new DomainException('Tabel tunda cair belum tersedia.');
        }

        return DB::transaction(function () use ($applicationId, $reason, $actor): string {
            $note = $this->support->note($reason);
            $application = DB::table('pengajuan_pinjaman')
                ->where('id', $applicationId)
                ->where('status', 'disetujui')
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('pinjaman')->whereColumn('pengajuan_id', 'pengajuan_pinjaman.id'))
                ->lockForUpdate()
                ->first();
            if (! $application) {
                throw new DomainException('Pengajuan tidak tersedia untuk TC.');
            }
            if (DB::table('layanan_tunda_cair')->where('pengajuan_id', $applicationId)->lockForUpdate()->exists()) {
                throw new DomainException('TC sudah pernah diputuskan Admin dan tidak dapat diajukan ulang.');
            }
            DB::table('layanan_tunda_cair')->insert([
                'pengajuan_id' => $applicationId,
                'user_id' => $actor->id,
                'status' => 'menunggu',
                'alasan' => $note,
                'created_at' => now(),
            ]);

            return $note;
        }, 3);
    }
}
