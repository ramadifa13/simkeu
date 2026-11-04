<?php

namespace App\Actions\Shu;

use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Ports native shuBayarHakPinjaman. */
class PayLoanShuEntitlementAction
{
    public function __construct(private readonly ShuSupport $support) {}

    public function handle(int $entitlementId, string $method, string $reference, string $proof, User $actor): int
    {
        $this->support->requireAdmin($actor);
        if (! Schema::hasTable('shu_hak_pinjaman')) {
            throw new DomainException('Modul SHU pinjaman belum tersedia.');
        }
        if (! in_array($method, ['cash', 'transfer'], true)) {
            throw new DomainException('Metode pembayaran tidak valid.');
        }
        $reference = trim($reference);
        if (mb_strlen($reference) < 3 || mb_strlen($reference) > 100) {
            throw new DomainException('Referensi pembayaran wajib 3–100 karakter.');
        }
        if ($proof === '') {
            throw new DomainException('Bukti pembayaran wajib diunggah.');
        }

        return DB::transaction(function () use ($entitlementId, $method, $reference, $proof, $actor): int {
            $row = DB::table('shu_hak_pinjaman')->where('id', $entitlementId)->lockForUpdate()->first();
            if (! $row || $row->status_bayar !== 'belum') {
                throw new DomainException('Hak SHU tidak ditemukan atau sudah dibayar.');
            }
            $txId = $this->support->cashOut((int) $row->anggota_id, (int) $row->jumlah, 'Pembayaran SHU pinjaman '.$row->pinjaman_id.' - '.$row->jenis, $actor);
            $updated = DB::table('shu_hak_pinjaman')->where('id', $entitlementId)->where('status_bayar', 'belum')->update([
                'status_bayar' => 'sudah',
                'metode' => $method,
                'bukti' => $proof,
                'referensi' => $reference,
                'dibayar_oleh' => $actor->id,
                'dibayar_at' => now(),
                'transaksi_id' => $txId,
            ]);
            if (! $updated) {
                throw new DomainException('Hak SHU sudah diproses oleh pengguna lain.');
            }
            if (Schema::hasTable('shu_hak_audit')) {
                DB::table('shu_hak_audit')->insert([
                    'hak_id' => $entitlementId,
                    'user_id' => $actor->id,
                    'aksi' => 'bayar',
                    'detail' => json_encode(['jumlah' => (int) $row->jumlah, 'metode' => $method, 'transaksi' => $txId], JSON_UNESCAPED_UNICODE),
                    'created_at' => now(),
                ]);
            }

            return $txId;
        }, 3);
    }
}
