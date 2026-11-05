<?php

namespace App\Actions\Savings;

use App\Models\Anggota;
use App\Models\JenisSimpanan;
use App\Models\Transaksi;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class CreateSavingsTransactionAction
{
    /** @var list<string> */
    private const RESERVED_TYPES = ['ANG', 'BONUS', 'SHU'];

    /**
     * @param  array{anggota_id:int,jenis_simpanan_id:int,jumlah:float|int|string,keterangan?:string|null}  $attributes
     */
    public function handle(string $kind, array $attributes, User $actor): Transaksi
    {
        if (! in_array($kind, ['setoran', 'penarikan'], true)) {
            throw new DomainException('Jenis transaksi simpanan tidak valid.');
        }

        return DB::transaction(function () use ($kind, $attributes, $actor): Transaksi {
            /** @var Anggota|null $member */
            $member = Anggota::query()->whereKey($attributes['anggota_id'])->lockForUpdate()->first();
            if (! $member || $member->status !== 'aktif') {
                throw new DomainException('Anggota tidak aktif atau tidak ditemukan.');
            }

            /** @var JenisSimpanan|null $type */
            $type = JenisSimpanan::query()->whereKey($attributes['jenis_simpanan_id'])->lockForUpdate()->first();
            if (! $type || $type->status !== 'aktif' || in_array(strtoupper($type->kode_jenis), self::RESERVED_TYPES, true)) {
                throw new DomainException('Jenis simpanan tidak tersedia untuk transaksi manual.');
            }

            $amount = round((float) $attributes['jumlah'], 2);
            if ($amount <= 0) {
                throw new DomainException('Nominal harus lebih dari Rp0.');
            }

            if ($kind === 'setoran') {
                if ((float) $type->minimum > 0 && $amount < (float) $type->minimum) {
                    throw new DomainException('Setoran minimum untuk jenis ini adalah Rp'.number_format((float) $type->minimum, 0, ',', '.').'.');
                }
                if ((float) $type->maksimum > 0 && $amount > (float) $type->maksimum) {
                    throw new DomainException('Setoran maksimum untuk jenis ini adalah Rp'.number_format((float) $type->maksimum, 0, ',', '.').'.');
                }
            }

            // Lock rekening anggota dan row kas terakhir before calculating balances.
            $saving = DB::table('simpanan')
                ->where('anggota_id', $member->id)
                ->where('jenis_simpanan_id', $type->id)
                ->lockForUpdate()
                ->first();
            DB::table('kas')->orderByDesc('id')->limit(1)->lockForUpdate()->get();

            $balanceBefore = $saving ? (float) $saving->saldo : 0.0;
            if ($kind === 'penarikan' && $amount < 1000) {
                throw new DomainException('Penarikan minimum adalah Rp1.000.');
            }
            if ($kind === 'penarikan' && $amount > $balanceBefore) {
                throw new DomainException('Saldo tidak mencukupi. Saldo tersedia: Rp'.number_format($balanceBefore, 0, ',', '.').'.');
            }

            $balanceAfter = $kind === 'setoran' ? $balanceBefore + $amount : $balanceBefore - $amount;
            $cashBefore = (float) DB::table('kas')->selectRaw("COALESCE(SUM(CASE WHEN jenis = 'masuk' THEN jumlah ELSE -jumlah END), 0) AS saldo")->value('saldo');
            if ($kind === 'penarikan' && $amount - $cashBefore > 0.005) {
                throw new DomainException('Saldo kas tidak cukup untuk penarikan.');
            }
            $cashAfter = $kind === 'setoran' ? $cashBefore + $amount : $cashBefore - $amount;

            $transaction = Transaksi::query()->create([
                'kode_transaksi' => $this->transactionCode($kind),
                'anggota_id' => $member->id,
                'jenis_simpanan_id' => $type->id,
                'jenis_transaksi' => $kind,
                'jumlah' => $amount,
                'saldo_sebelum' => $balanceBefore,
                'saldo_sesudah' => $balanceAfter,
                'keterangan' => filled($attributes['keterangan'] ?? null) ? trim((string) $attributes['keterangan']) : null,
                'user_id' => $actor->id,
            ]);

            if ($saving) {
                DB::table('simpanan')->where('id', $saving->id)->update(['saldo' => $balanceAfter]);
            } else {
                DB::table('simpanan')->insert([
                    'anggota_id' => $member->id,
                    'jenis_simpanan_id' => $type->id,
                    'saldo' => $balanceAfter,
                ]);
            }

            DB::table('kas')->insert(\App\Support\CashEntry::attributes([
                'transaksi_id' => $transaction->id,
                'jenis' => $kind === 'setoran' ? 'masuk' : 'keluar',
                'jumlah' => $amount,
                'saldo_sebelum' => $cashBefore,
                'saldo_sesudah' => $cashAfter,
                'keterangan' => $transaction->keterangan ?: ucfirst($kind).' simpanan '.$member->kode_anggota,
            ], \App\Support\CashEntry::TRANSAKSI));

            return $transaction;
        }, 3);
    }

    private function transactionCode(string $kind): string
    {
        $prefix = $kind === 'setoran' ? 'SET' : 'TAR';

        return sprintf('%s-%s-%03d', $prefix, now()->format('ymdHis'), random_int(1, 999));
    }
}
