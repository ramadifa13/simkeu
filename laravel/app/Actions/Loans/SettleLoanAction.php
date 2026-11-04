<?php

namespace App\Actions\Loans;

use App\Actions\Cash\RecalculateCashLedgerAction;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Records a full settlement using the same invariants as the native Pelunasan screen. */
class SettleLoanAction
{
    public function __construct(private readonly RecalculateCashLedgerAction $cashLedger) {}

    /**
     * @param  array{foto:string,ttd:string,ttd_teller:string}|null  $immediateCollateral
     * @return array{loan_id:int,total:float,discount:float,cash_id:int}
     */
    public function handle(int $loanId, string $paymentDate, string $method, User $actor, ?float $approvedDiscount = null, string $note = '', string $proof = '', ?array $immediateCollateral = null): array
    {
        return DB::transaction(function () use ($loanId, $paymentDate, $method, $actor, $approvedDiscount, $note, $proof, $immediateCollateral): array {
            if (! in_array($method, ['cash', 'transfer'], true)) {
                throw new DomainException('Metode pembayaran tidak valid.');
            }
            if (CarbonImmutable::parse($paymentDate)->isAfter(today())) {
                throw new DomainException('Tanggal pelunasan tidak boleh di masa depan.');
            }

            $loan = DB::table('pinjaman')->where('id', $loanId)->lockForUpdate()->first();
            if (! $loan || $loan->status !== 'aktif') {
                throw new DomainException('Pinjaman tidak aktif atau sudah lunas.');
            }
            $loan->memerlukan_jaminan = Schema::hasTable('jenis_pinjaman')
                ? (bool) DB::table('jenis_pinjaman')->where('id', $loan->jenis_pinjaman_id)->value('memerlukan_jaminan')
                : false;
            $installments = DB::table('angsuran')->where('pinjaman_id', $loanId)->where('status', '<>', 'lunas')->orderBy('angsuran_ke')->lockForUpdate()->get();
            if ($installments->isEmpty()) {
                throw new DomainException('Tidak ada sisa angsuran untuk dilunasi.');
            }

            $penalties = 0.0;
            foreach ($installments as $installment) {
                $paid = Schema::hasTable('layanan_bayar_denda')
                    ? (float) DB::table('layanan_bayar_denda')->where('angsuran_id', $installment->id)->lockForUpdate()->sum('jumlah')
                    : 0.0;
                $penalties += max(0, (float) $installment->denda - $paid);
            }
            if ($penalties > 0.005) {
                throw new DomainException('Bayar seluruh sisa denda melalui menu Denda terlebih dahulu.');
            }

            $discount = max(0, min(100, (float) ($approvedDiscount ?? 0)));
            $remainingInterest = (float) $installments->sum('bunga');
            $discountAmount = round($remainingInterest * $discount / 100, 2);
            $gross = (float) $installments->sum('jumlah_angsuran');
            $total = max(0, round($gross - $discountAmount, 2));

            DB::table('kas')->orderByDesc('id')->limit(1)->lockForUpdate()->get();
            $before = (float) DB::table('kas')->selectRaw("COALESCE(SUM(CASE WHEN jenis='masuk' THEN jumlah ELSE -jumlah END),0) as saldo")->value('saldo');
            DB::table('angsuran')->where('pinjaman_id', $loanId)->where('status', '<>', 'lunas')->update([
                'status' => 'lunas', 'tanggal_bayar' => $paymentDate, 'cara_bayar' => 'pelunasan',
            ]);
            DB::table('pinjaman')->where('id', $loanId)->update(['status' => 'lunas', 'sisa_pokok' => 0, 'tanggal_lunas' => $paymentDate]);
            $cashId = DB::table('kas')->insertGetId([
                'transaksi_id' => $loanId, 'jenis' => 'masuk', 'jumlah' => $total, 'saldo_sebelum' => $before, 'saldo_sesudah' => $before + $total,
                'keterangan' => 'Pelunasan '.$loan->kode_pinjaman.($note !== '' ? ' — '.$note : ''), 'tanggal' => $paymentDate.' '.now()->format('H:i:s'),
            ]);

            if (Schema::hasTable('pelunasan_dini')) {
                DB::table('pelunasan_dini')->insert(['pinjaman_id' => $loanId, 'jumlah_pelunasan' => $total, 'keterangan' => $note, 'user_id' => $actor->id]);
            }
            if (Schema::hasTable('layanan_pembayaran')) {
                DB::table('layanan_pembayaran')->insert([
                    'pinjaman_id' => $loanId, 'user_id' => $actor->id, 'jenis' => 'pelunasan', 'jumlah' => $total,
                    'metode' => $method, 'bukti' => $proof, 'bunga_diskon' => $discountAmount,
                ]);
            }
            if (Schema::hasTable('layanan_pelunasan')) {
                DB::table('layanan_pelunasan')->where('pinjaman_id', $loanId)->where('status', 'disetujui')->update(['status' => 'selesai']);
            }

            if ($immediateCollateral !== null) {
                if (! (bool) $loan->memerlukan_jaminan) {
                    throw new DomainException('Pengambilan jaminan langsung hanya untuk pinjaman berjaminan.');
                }
                $collateral = DB::table('jaminan_pinjaman')->where('pinjaman_id', $loanId)->where('status_jaminan', 'dititipkan')->lockForUpdate()->first();
                if (! $collateral) {
                    throw new DomainException('Tidak ada jaminan titipan yang dapat diambil saat pelunasan.');
                }
                foreach (['foto', 'ttd', 'ttd_teller'] as $key) {
                    if (blank($immediateCollateral[$key] ?? null)) {
                        throw new DomainException('Foto dan tanda tangan serah terima jaminan wajib dilengkapi.');
                    }
                }
                DB::table('jaminan_pinjaman')->where('id', $collateral->id)->update([
                    'status_jaminan' => 'dikembalikan',
                    'tanggal_dikembalikan' => $paymentDate,
                ]);
                if (Schema::hasTable('layanan_jaminan_kembali')) {
                    $payload = [
                        'user_id' => $actor->id,
                        'status' => 'diserahkan',
                        'foto' => $immediateCollateral['foto'],
                        'ttd' => $immediateCollateral['ttd'],
                        'ttd_teller' => $immediateCollateral['ttd_teller'],
                        'diserahkan_at' => now(),
                        'alasan' => 'Pengambilan langsung saat pelunasan.',
                        'catatan_admin' => 'Diserahkan bersamaan dengan pelunasan.',
                        'diperiksa_at' => now(),
                    ];
                    $existing = DB::table('layanan_jaminan_kembali')->where('pinjaman_id', $loanId)->lockForUpdate()->first();
                    $existing
                        ? DB::table('layanan_jaminan_kembali')->where('id', $existing->id)->update($payload)
                        : DB::table('layanan_jaminan_kembali')->insert(['pinjaman_id' => $loanId, ...$payload]);
                }
            }

            $this->cashLedger->recalculateLocked();

            return ['loan_id' => $loanId, 'total' => $total, 'discount' => $discountAmount, 'cash_id' => $cashId];
        }, 3);
    }
}
