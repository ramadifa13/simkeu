<?php

namespace App\Actions\Shu;

use App\Actions\Cash\RecalculateCashLedgerAction;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Shared SHU helpers ported from native shu_helper.php. */
class ShuSupport
{
    public const CATEGORIES = [
        'simpanan' => 'Jasa simpanan',
        'pinjaman' => 'Jasa pinjaman',
        'transaksi' => 'Jasa transaksi Waserda',
        'cadangan' => 'Dana cadangan',
        'pengurus' => 'Pengurus',
        'pengawas' => 'Pengawas',
        'sosial' => 'Dana sosial',
        'pendidikan' => 'Pendidikan',
    ];

    public function __construct(private readonly RecalculateCashLedgerAction $cashLedger) {}

    public function requireAdmin(User $actor): void
    {
        if ($actor->role !== 'admin') {
            throw new DomainException('Tindakan SHU hanya untuk Admin.');
        }
    }

    public function ready(): bool
    {
        return Schema::hasTable('shu_periode')
            && Schema::hasColumn('shu_periode', 'kebijakan_json')
            && Schema::hasTable('shu_anggota')
            && Schema::hasColumn('shu_anggota', 'pembayaran_id')
            && Schema::hasTable('shu_pembayaran')
            && Schema::hasColumn('shu_pembayaran', 'nonce')
            && Schema::hasTable('shu_audit');
    }

    public function period(int $id, bool $lock = true): object
    {
        $query = DB::table('shu_periode')->where('id', $id);
        if ($lock) {
            $query->lockForUpdate();
        }
        $period = $query->first();
        if (! $period) {
            throw new DomainException('Periode SHU tidak ditemukan.');
        }

        return $period;
    }

    public function rupiah(mixed $value, bool $allowZero = false): int
    {
        if (! is_scalar($value) || ! preg_match('/^\d{1,13}$/D', (string) $value) || (int) $value > 1000000000000 || (! $allowZero && (int) $value === 0)) {
            throw new DomainException('Nominal harus dalam rupiah bulat, positif, maksimal Rp 1 triliun.');
        }

        return (int) $value;
    }

    public function percent(mixed $value): int
    {
        if (! is_scalar($value) || ! preg_match('/^\d{1,3}(?:\.\d{1,2})?$/D', (string) $value)) {
            throw new DomainException('Persentase tidak valid. Gunakan angka 0–100 dengan maksimal dua desimal.');
        }
        $cents = (int) round((float) $value * 100);
        if ($cents > 10000) {
            throw new DomainException('Persentase harus antara 0 dan 100.');
        }

        return $cents;
    }

    /** @param  array<string, mixed>  $input @return array<string, mixed> */
    public function policy(array $input): array
    {
        $bp = [];
        foreach (array_keys(self::CATEGORIES) as $key) {
            $bp[$key] = $this->percent($input['persen_'.$key] ?? '');
        }
        if (array_sum($bp) !== 10000) {
            throw new DomainException('Total persentase alokasi wajib tepat 100%.');
        }
        $basis = (string) ($input['basis_simpanan'] ?? '');
        if (! in_array($basis, ['rata_harian', 'saldo_akhir'], true)) {
            throw new DomainException('Pilih dasar jasa simpanan.');
        }

        return [
            'versi' => 1,
            'persen_bp' => $bp,
            'basis_simpanan' => $basis,
            'basis_pinjaman' => 'bunga_dibayar_setelah_diskon',
            'basis_transaksi' => 'belanja_waserda',
            'anggota' => 'terdaftar_sampai_akhir_periode',
        ];
    }

    /** @param  array<int|string, int>  $weights @return array<int|string, int> */
    public function allocate(int $pool, array $weights): array
    {
        $result = array_fill_keys(array_keys($weights), 0);
        $sum = array_sum($weights);
        if ($pool === 0) {
            return $result;
        }
        if ($sum <= 0) {
            throw new DomainException('Alokasi positif tidak memiliki kontribusi. Ubah kebijakan atau periksa transaksi sumber.');
        }
        $fractions = [];
        $used = 0;
        foreach ($weights as $key => $weight) {
            if ($weight < 0) {
                throw new DomainException('Kontribusi negatif tidak dapat digunakan.');
            }
            $raw = $pool * ($weight / $sum);
            $result[$key] = (int) floor($raw);
            $used += $result[$key];
            $fractions[$key] = $raw - $result[$key];
        }
        arsort($fractions, SORT_NUMERIC);
        $left = $pool - $used;
        if ($left < 0 || $left > count($weights)) {
            throw new DomainException('Pembulatan alokasi tidak konsisten.');
        }
        foreach ($fractions as $key => $unused) {
            if ($left-- <= 0) {
                break;
            }
            $result[$key]++;
        }

        return $result;
    }

    public function audit(int $periodId, User $actor, string $action, array $detail): void
    {
        DB::table('shu_audit')->insert([
            'periode_id' => $periodId,
            'user_id' => $actor->id,
            'aksi' => $action,
            'detail' => json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }

    /** @param  array<string, int>  $events */
    public function savingsBasis(array $events, string $start, string $end, string $basis): float
    {
        $startDate = now()->parse($start)->startOfDay();
        $endDate = now()->parse($end)->startOfDay();
        $after = $endDate->copy()->addDay();
        $balance = 0;
        $weighted = 0;
        $cursor = $startDate->copy();
        ksort($events);
        foreach ($events as $date => $cents) {
            $day = now()->parse($date)->startOfDay();
            if ($day->gt($endDate)) {
                break;
            }
            if ($day->lt($startDate)) {
                $balance += $cents;
                continue;
            }
            $weighted += max(0, $balance) * $cursor->diffInDays($day);
            $balance += $cents;
            $cursor = $day->copy();
        }
        $weighted += max(0, $balance) * $cursor->diffInDays($after);
        if ($basis === 'saldo_akhir') {
            return max(0, $balance) / 100;
        }

        return round($weighted / max(1, $startDate->diffInDays($after)) / 100, 2);
    }

    /** @return array{rows: array<int, array<string, mixed>>, alokasi: array<string, int>, hash: string} */
    public function simulate(object $period): array
    {
        $policy = json_decode((string) $period->kebijakan_json, true, 512, JSON_THROW_ON_ERROR);
        $start = (string) $period->tanggal_mulai;
        $end = (string) $period->tanggal_selesai;
        $members = DB::table('anggota')->whereDate('tanggal_daftar', '<=', $end)->orderBy('id')->get(['id', 'nama_lengkap', 'kode_anggota']);
        $rows = [];
        foreach ($members as $member) {
            $events = DB::table('transaksi as t')
                ->join('jenis_simpanan as j', 'j.id', '=', 't.jenis_simpanan_id')
                ->where('t.anggota_id', $member->id)
                ->where('t.tanggal_transaksi', '<', now()->parse($end)->addDay()->toDateString())
                ->whereNotIn('j.kode_jenis', ['ANG', 'BONUS', 'SHU'])
                ->selectRaw("DATE(t.tanggal_transaksi) as hari, SUM(CASE WHEN t.jenis_transaksi='setoran' THEN t.jumlah ELSE -t.jumlah END) as jumlah")
                ->groupBy(DB::raw('DATE(t.tanggal_transaksi)'))
                ->orderBy('hari')
                ->get();
            $days = [];
            foreach ($events as $event) {
                $days[(string) $event->hari] = (int) round(((float) $event->jumlah) * 100);
            }
            $interest = (float) DB::table('angsuran as a')->join('pinjaman as p', 'p.id', '=', 'a.pinjaman_id')
                ->where('p.anggota_id', $member->id)->where('a.status', 'lunas')
                ->whereBetween('a.tanggal_bayar', [$start, $end])->sum('a.bunga');
            $discount = Schema::hasTable('layanan_pembayaran')
                ? (float) DB::table('layanan_pembayaran as b')->join('pinjaman as p', 'p.id', '=', 'b.pinjaman_id')
                    ->where('p.anggota_id', $member->id)->whereNull('b.dibatalkan_at')
                    ->where('b.created_at', '>=', $start)
                    ->where('b.created_at', '<', now()->parse($end)->addDay()->toDateTimeString())
                    ->sum('b.bunga_diskon')
                : 0.0;
            $shop = Schema::hasTable('waserda_transaksi')
                ? (float) DB::table('waserda_transaksi')->where('anggota_id', $member->id)
                    ->where('created_at', '>=', $start)
                    ->where('created_at', '<', now()->parse($end)->addDay()->toDateTimeString())
                    ->sum('total_bayar')
                : 0.0;
            $rows[(int) $member->id] = [
                'anggota_id' => (int) $member->id,
                'nama_snapshot' => $member->nama_lengkap,
                'kode_snapshot' => $member->kode_anggota,
                'total_simpanan' => $this->savingsBasis($days, $start, $end, $policy['basis_simpanan']),
                'total_pinjaman' => max(0, round($interest - $discount, 2)),
                'total_transaksi' => max(0, $shop),
            ];
        }

        $alloc = $this->allocate((int) $period->total_shu, $policy['persen_bp']);
        foreach (['simpanan', 'pinjaman', 'transaksi'] as $key) {
            $weights = [];
            foreach ($rows as $id => $row) {
                $weights[$id] = (int) round($row['total_'.$key] * 100);
            }
            $shares = $this->allocate($alloc[$key], $weights);
            foreach ($rows as $id => &$row) {
                $row['jasa_'.$key] = $shares[$id];
            }
            unset($row);
        }
        foreach ($rows as &$row) {
            $row['total_shu'] = $row['jasa_simpanan'] + $row['jasa_pinjaman'] + $row['jasa_transaksi'];
        }
        unset($row);

        return [
            'rows' => $rows,
            'alokasi' => $alloc,
            'hash' => hash('sha256', json_encode([(int) $period->total_shu, $policy, $rows], JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR)),
        ];
    }

    public function cashOut(int $memberId, int $amount, string $description, User $actor): int
    {
        if ($amount <= 0) {
            throw new DomainException('Nominal pengeluaran harus positif.');
        }
        DB::table('kas')->orderByDesc('id')->limit(1)->lockForUpdate()->get();
        $before = (float) DB::table('kas')->orderByDesc('id')->value('saldo_sesudah');
        if ($amount > $before) {
            throw new DomainException('Saldo kas tidak mencukupi. Pembayaran SHU belum dicatat.');
        }
        $typeId = DB::table('jenis_simpanan')->where('kode_jenis', 'SHU')->value('id');
        if (! $typeId) {
            $typeId = DB::table('jenis_simpanan')->insertGetId([
                'kode_jenis' => 'SHU',
                'nama_jenis' => 'Pembagian SHU',
                'deskripsi' => 'Jurnal pembagian SHU; bukan rekening simpanan anggota',
                'status' => 'aktif',
            ]);
        }
        $txId = DB::table('transaksi')->insertGetId([
            'kode_transaksi' => 'SHU-'.bin2hex(random_bytes(8)),
            'anggota_id' => $memberId,
            'jenis_simpanan_id' => $typeId,
            'jenis_transaksi' => 'penarikan',
            'jumlah' => $amount,
            'saldo_sebelum' => 0,
            'saldo_sesudah' => 0,
            'keterangan' => $description,
            'user_id' => $actor->id,
            'tanggal_transaksi' => now(),
        ]);
        DB::table('kas')->insert(\App\Support\CashEntry::attributes([
            'transaksi_id' => $txId,
            'jenis' => 'keluar',
            'jumlah' => $amount,
            'saldo_sebelum' => $before,
            'saldo_sesudah' => $before - $amount,
            'keterangan' => $description,
            'tanggal' => now(),
        ], \App\Support\CashEntry::SHU));
        $this->cashLedger->recalculateLocked();

        return $txId;
    }

    public function cashIn(int $memberId, int $amount, string $description, User $actor): int
    {
        DB::table('kas')->orderByDesc('id')->limit(1)->lockForUpdate()->get();
        $before = (float) DB::table('kas')->orderByDesc('id')->value('saldo_sesudah');
        $typeId = DB::table('jenis_simpanan')->where('kode_jenis', 'SHU')->value('id')
            ?: DB::table('jenis_simpanan')->insertGetId(['kode_jenis' => 'SHU', 'nama_jenis' => 'Pembagian SHU', 'deskripsi' => 'Jurnal SHU', 'status' => 'aktif']);
        $txId = DB::table('transaksi')->insertGetId([
            'kode_transaksi' => 'SHU-'.bin2hex(random_bytes(8)),
            'anggota_id' => $memberId,
            'jenis_simpanan_id' => $typeId,
            'jenis_transaksi' => 'setoran',
            'jumlah' => $amount,
            'saldo_sebelum' => 0,
            'saldo_sesudah' => 0,
            'keterangan' => $description,
            'user_id' => $actor->id,
            'tanggal_transaksi' => now(),
        ]);
        DB::table('kas')->insert(\App\Support\CashEntry::attributes([
            'transaksi_id' => $txId,
            'jenis' => 'masuk',
            'jumlah' => $amount,
            'saldo_sebelum' => $before,
            'saldo_sesudah' => $before + $amount,
            'keterangan' => $description,
            'tanggal' => now(),
        ], \App\Support\CashEntry::SHU));
        $this->cashLedger->recalculateLocked();

        return $txId;
    }
}
