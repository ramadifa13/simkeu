<?php

namespace App\Actions\Layanan;

use App\Actions\Cash\RecalculateCashLedgerAction;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Shared helpers for Phase 8 layanan actions. */
class LayananSupport
{
    public function __construct(private readonly RecalculateCashLedgerAction $cashLedger) {}

    public function loan(int $loanId, bool $lock = true): object
    {
        $query = DB::table('pinjaman as p')
            ->join('jenis_pinjaman as jp', 'jp.id', '=', 'p.jenis_pinjaman_id')
            ->join('anggota as a', 'a.id', '=', 'p.anggota_id')
            ->where('p.id', $loanId)
            ->select(['p.*', 'jp.memerlukan_jaminan', 'jp.nama_jenis', 'a.nama_lengkap', 'a.kode_anggota', 'a.telepon']);
        if ($lock) {
            $query->lockForUpdate();
        }
        $loan = $query->first();
        if (! $loan) {
            throw new DomainException('Pinjaman tidak ditemukan.');
        }

        return $loan;
    }

    public function requireAdmin(User $actor): void
    {
        if ($actor->role !== 'admin') {
            throw new DomainException('Tindakan ini hanya untuk Admin.');
        }
    }

    public function note(string $note, int $min = 5): string
    {
        $note = trim($note);
        if (mb_strlen($note) < $min) {
            throw new DomainException('Catatan minimal '.$min.' karakter.');
        }
        if (mb_strlen($note) > 2000) {
            throw new DomainException('Catatan maksimal 2000 karakter.');
        }

        return $note;
    }

    public function audit(int $loanId, User $actor, string $action, string $detail): void
    {
        if (Schema::hasTable('layanan_audit')) {
            DB::table('layanan_audit')->insert([
                'pinjaman_id' => $loanId,
                'user_id' => $actor->id,
                'aksi' => $action,
                'detail' => $detail,
                'created_at' => now(),
            ]);
        }
    }

    public function cashIn(int $referenceId, float $amount, string $note): int
    {
        DB::table('kas')->orderByDesc('id')->limit(1)->lockForUpdate()->get();
        $before = (float) DB::table('kas')->selectRaw("COALESCE(SUM(CASE WHEN jenis='masuk' THEN jumlah ELSE -jumlah END),0) as saldo")->value('saldo');

        return DB::table('kas')->insertGetId([
            'transaksi_id' => $referenceId,
            'jenis' => 'masuk',
            'jumlah' => $amount,
            'saldo_sebelum' => $before,
            'saldo_sesudah' => $before + $amount,
            'keterangan' => $note,
            'tanggal' => now(),
        ]);
    }

    public function cashOut(int $memberId, float $amount, string $note, User $actor): int
    {
        if ($amount <= 0) {
            throw new DomainException('Nominal pengeluaran harus positif.');
        }
        DB::table('kas')->orderByDesc('id')->limit(1)->lockForUpdate()->get();
        $before = (float) DB::table('kas')->selectRaw("COALESCE(SUM(CASE WHEN jenis='masuk' THEN jumlah ELSE -jumlah END),0) as saldo")->value('saldo');
        if ($amount - $before > 0.005) {
            throw new DomainException('Saldo kas tidak cukup untuk pengeluaran.');
        }

        $typeId = DB::table('jenis_simpanan')->where('kode_jenis', 'BONUS')->value('id');
        if (! $typeId) {
            $typeId = DB::table('jenis_simpanan')->insertGetId([
                'kode_jenis' => 'BONUS',
                'nama_jenis' => 'Bonus dan pengembalian',
                'deskripsi' => 'Pengeluaran layanan nasabah',
                'status' => 'aktif',
            ]);
        }

        $txId = DB::table('transaksi')->insertGetId([
            'kode_transaksi' => 'OUT-'.bin2hex(random_bytes(4)),
            'anggota_id' => $memberId,
            'jenis_simpanan_id' => $typeId,
            'jenis_transaksi' => 'penarikan',
            'jumlah' => $amount,
            'saldo_sebelum' => 0,
            'saldo_sesudah' => 0,
            'keterangan' => $note,
            'user_id' => $actor->id,
            'tanggal_transaksi' => now(),
        ]);

        DB::table('kas')->insert([
            'transaksi_id' => $txId,
            'jenis' => 'keluar',
            'jumlah' => $amount,
            'saldo_sebelum' => $before,
            'saldo_sesudah' => $before - $amount,
            'keterangan' => $note,
            'tanggal' => now(),
        ]);
        $this->cashLedger->recalculateLocked();

        return $txId;
    }

    public function remainingDebt(int $loanId, float $discountPercent = 0): array
    {
        $installments = DB::table('angsuran')->where('pinjaman_id', $loanId)->where('status', '<>', 'lunas')->get();
        $principal = (float) $installments->sum('pokok');
        $interest = (float) $installments->sum('bunga');
        $discount = round($interest * max(0, min(100, $discountPercent)) / 100, 2);
        $penalty = 0.0;
        $paidPenalty = 0.0;
        foreach ($installments as $row) {
            $penalty += (float) $row->denda;
            $paidPenalty += (float) DB::table('layanan_bayar_denda')->where('angsuran_id', $row->id)->sum('jumlah');
        }
        $credit = Schema::hasTable('layanan_lelang')
            ? (float) DB::table('layanan_lelang')->where('pinjaman_id', $loanId)->value('kredit')
            : 0.0;
        $total = round(max(0, $principal + $interest - $discount + max(0, $penalty - $paidPenalty) - $credit), 2);

        return [
            'jumlah' => $installments->count(),
            'pokok' => $principal,
            'bunga' => $interest,
            'diskon_bunga' => $discount,
            'denda' => $penalty,
            'denda_dibayar' => $paidPenalty,
            'kredit_lelang' => $credit,
            'total' => $total,
        ];
    }

    public function priorityEligible(int $memberId): bool
    {
        $loans = DB::table('pinjaman as p')
            ->where('p.anggota_id', $memberId)
            ->where('p.status', 'lunas')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('angsuran as ax')->whereColumn('ax.pinjaman_id', 'p.id'))
            ->when(Schema::hasColumn('pinjaman', 'tanggal_lunas'), fn ($q) => $q->orderByDesc('p.tanggal_lunas'))
            ->orderByDesc('p.id')
            ->limit(2)
            ->get(['p.id']);
        if ($loans->count() !== 2) {
            return false;
        }
        foreach ($loans as $loan) {
            $late = DB::table('angsuran')
                ->where('pinjaman_id', $loan->id)
                ->whereRaw("(status <> 'lunas' OR tanggal_bayar IS NULL OR DATE(tanggal_bayar) > tanggal_jatuh_tempo)")
                ->exists();
            if ($late) {
                return false;
            }
        }

        return true;
    }

    public function pemutihanEnabled(): bool
    {
        if (! Schema::hasTable('layanan_pengaturan')) {
            return false;
        }

        return (bool) DB::table('layanan_pengaturan')->where('id', 1)->value('pemutihan');
    }

    public function markLoanPaidIfDone(int $loanId): void
    {
        $open = DB::table('angsuran')->where('pinjaman_id', $loanId)->where('status', '<>', 'lunas')->exists();
        if (! $open) {
            $payload = ['status' => 'lunas', 'sisa_pokok' => 0];
            if (Schema::hasColumn('pinjaman', 'tanggal_lunas')) {
                $payload['tanggal_lunas'] = today()->toDateString();
            }
            DB::table('pinjaman')->where('id', $loanId)->update($payload);
        }
    }

    public function recalculateCash(): void
    {
        $this->cashLedger->recalculateLocked();
    }
}
