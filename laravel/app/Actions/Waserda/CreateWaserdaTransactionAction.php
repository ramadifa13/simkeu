<?php

namespace App\Actions\Waserda;

use App\Models\Anggota;
use App\Models\User;
use App\Models\WaserdaTransaction;
use DomainException;
use Illuminate\Support\Facades\DB;

class CreateWaserdaTransactionAction
{
    /** @param array{anggota_id:int,metode_bayar:string,items:array<int,array{nama:string,harga:float|int|string,jumlah:float|int|string}>} $attributes */
    public function handle(array $attributes, User $actor): WaserdaTransaction
    {
        return DB::transaction(function () use ($attributes, $actor): WaserdaTransaction {
            $member = Anggota::query()->whereKey($attributes['anggota_id'])->lockForUpdate()->first();
            if (! $member || $member->status !== 'aktif') {
                throw new DomainException('Anggota tidak aktif atau tidak ditemukan.');
            }

            $items = collect($attributes['items'])->map(function (array $item): array {
                $name = trim((string) $item['nama']);
                $price = round((float) $item['harga'], 2);
                $quantity = round((float) $item['jumlah'], 2);
                if ($name === '' || $price <= 0 || $quantity <= 0) {
                    throw new DomainException('Setiap barang harus memiliki nama, harga, dan jumlah yang valid.');
                }
                if ($price > 999999999999 || $quantity > 999999) {
                    throw new DomainException('Harga atau jumlah barang melebihi batas yang diizinkan.');
                }

                return ['nama' => $name, 'harga' => $price, 'jumlah' => $quantity, 'subtotal' => round($price * $quantity, 2)];
            })->values()->all();

            if ($items === []) {
                throw new DomainException('Minimal satu barang harus diisi.');
            }

            // Serialize all cash postings during balance calculation.
            DB::table('kas')->orderByDesc('id')->limit(1)->lockForUpdate()->get();
            $total = round((float) collect($items)->sum('subtotal'), 2);
            $cashBefore = (float) DB::table('kas')->selectRaw("COALESCE(SUM(CASE WHEN jenis = 'masuk' THEN jumlah ELSE -jumlah END), 0) AS saldo")->value('saldo');
            $transaction = WaserdaTransaction::query()->create([
                'kode_transaksi' => sprintf('WSD-%s-%03d', now()->format('ymdHis'), random_int(1, 999)),
                'anggota_id' => $member->id,
                'items' => $items,
                'total_bayar' => $total,
                'metode_bayar' => $attributes['metode_bayar'],
                'user_id' => $actor->id,
            ]);

            DB::table('kas')->insert([
                // Legacy schema uses this generic link even for Waserda rows.
                'transaksi_id' => $transaction->id,
                'jenis' => 'masuk',
                'jumlah' => $total,
                'saldo_sebelum' => $cashBefore,
                'saldo_sesudah' => $cashBefore + $total,
                'keterangan' => 'Pembelian Waserda - '.$transaction->kode_transaksi.' ('.count($items).' item)',
            ]);

            return $transaction;
        }, 3);
    }
}
