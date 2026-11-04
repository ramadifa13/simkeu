<?php

namespace App\Actions\Layanan;

use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Admin decision on a pending delay-disbursement request. */
class DecideDelayDisbursementAction
{
    public function __construct(private readonly LayananSupport $support) {}

    /**
     * @param  'disetujui'|'ditolak'  $decision
     */
    public function handle(int $requestId, string $decision, string $adminNote, User $actor): string
    {
        $this->support->requireAdmin($actor);
        if (! Schema::hasTable('layanan_tunda_cair')) {
            throw new DomainException('Tabel tunda cair belum tersedia.');
        }
        if (! in_array($decision, ['disetujui', 'ditolak'], true)) {
            throw new DomainException('Keputusan TC tidak valid.');
        }

        return DB::transaction(function () use ($requestId, $decision, $adminNote, $actor): string {
            $note = $this->support->note($adminNote);
            $updated = DB::table('layanan_tunda_cair')->where('id', $requestId)->where('status', 'menunggu')->update([
                'status' => $decision,
                'admin_id' => $actor->id,
                'catatan_admin' => $note,
                'diputuskan_at' => now(),
            ]);
            if (! $updated) {
                throw new DomainException('Pengajuan TC tidak ditemukan atau sudah diputuskan.');
            }
            if ($decision === 'disetujui') {
                $applicationId = (int) DB::table('layanan_tunda_cair')->where('id', $requestId)->value('pengajuan_id');
                $application = DB::table('pengajuan_pinjaman')
                    ->where('id', $applicationId)
                    ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('pinjaman')->whereColumn('pengajuan_id', 'pengajuan_pinjaman.id'))
                    ->lockForUpdate()
                    ->first();
                if (! $application) {
                    throw new DomainException('Pengajuan tidak dapat dibatalkan karena sudah dicairkan.');
                }
                $existingNote = trim((string) ($application->catatan_admin ?? ''));
                DB::table('pengajuan_pinjaman')->where('id', $applicationId)->update([
                    'status' => 'batal_cair',
                    'catatan_admin' => trim($existingNote."\nBatal cair: ".$note),
                    'admin_id' => $actor->id,
                    'tanggal_persetujuan' => now(),
                ]);
            }

            return $note;
        }, 3);
    }
}
