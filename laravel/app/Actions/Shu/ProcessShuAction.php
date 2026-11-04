<?php

namespace App\Actions\Shu;

use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Ports native shuProses for period lifecycle and payments. */
class ProcessShuAction
{
    public function __construct(private readonly ShuSupport $support) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(string $action, array $input, User $actor): int
    {
        $this->support->requireAdmin($actor);
        if (! $this->support->ready()) {
            throw new DomainException('Modul SHU memerlukan skema database terbaru.');
        }

        return DB::transaction(function () use ($action, $input, $actor): int {
            return match ($action) {
                'buat' => $this->create($input, $actor),
                'ubah' => $this->update($input, $actor),
                'hitung' => $this->calculate($input, $actor),
                'sahkan' => $this->approve($input, $actor),
                'bayar' => $this->pay($input, $actor),
                'balik' => $this->reverse($input, $actor),
                default => throw new DomainException('Tindakan SHU tidak dikenal.'),
            };
        }, 3);
    }

    /** @param  array<string, mixed>  $input */
    private function create(array $input, User $actor): int
    {
        $year = filter_var($input['tahun'] ?? null, FILTER_VALIDATE_INT);
        if (! $year || $year < 2000 || $year > (int) now()->format('Y')) {
            throw new DomainException('Tahun harus antara 2000 dan tahun berjalan.');
        }
        if (DB::table('shu_periode')->where('tahun', $year)->exists()) {
            throw new DomainException('Periode SHU untuk tahun tersebut sudah ada.');
        }
        $pool = $this->support->rupiah($input['total_shu'] ?? '');
        $policy = $this->support->policy($input);
        $id = DB::table('shu_periode')->insertGetId([
            'tahun' => $year,
            'nama_periode' => 'SHU Tahun '.$year,
            'tanggal_mulai' => $year.'-01-01',
            'tanggal_selesai' => $year.'-12-31',
            'total_shu' => $pool,
            'kebijakan_json' => json_encode($policy, JSON_THROW_ON_ERROR),
            'status' => 'draft',
            'dibuat_oleh' => $actor->id,
            'created_at' => now(),
        ]);
        $this->support->audit($id, $actor, 'buat', ['pool' => $pool, 'kebijakan' => $policy]);

        return $id;
    }

    /** @param  array<string, mixed>  $input */
    private function update(array $input, User $actor): int
    {
        $id = (int) ($input['periode_id'] ?? 0);
        $period = $this->support->period($id);
        if ($period->status === 'final') {
            throw new DomainException('Periode final terkunci. Riwayat pembagian tidak dapat diubah.');
        }
        $policy = $this->support->policy($input);
        $pool = $this->support->rupiah($input['total_shu'] ?? '');
        DB::table('shu_anggota')->where('shu_periode_id', $id)->delete();
        DB::table('shu_periode')->where('id', $id)->update([
            'total_shu' => $pool,
            'kebijakan_json' => json_encode($policy, JSON_THROW_ON_ERROR),
            'status' => 'draft',
            'snapshot_hash' => null,
            'alokasi_json' => null,
            'dihitung_at' => null,
        ]);
        $this->support->audit($id, $actor, 'ubah', ['pool' => $pool, 'kebijakan' => $policy]);

        return $id;
    }

    /** @param  array<string, mixed>  $input */
    private function calculate(array $input, User $actor): int
    {
        $id = (int) ($input['periode_id'] ?? 0);
        $period = $this->support->period($id);
        if ($period->status === 'final') {
            throw new DomainException('Periode final terkunci. Riwayat pembagian tidak dapat diubah.');
        }
        $sim = $this->support->simulate($period);
        DB::table('shu_anggota')->where('shu_periode_id', $id)->delete();
        foreach ($sim['rows'] as $row) {
            DB::table('shu_anggota')->insert([
                'shu_periode_id' => $id,
                'anggota_id' => $row['anggota_id'],
                'nama_snapshot' => $row['nama_snapshot'],
                'kode_snapshot' => $row['kode_snapshot'],
                'total_simpanan' => $row['total_simpanan'],
                'total_pinjaman' => $row['total_pinjaman'],
                'total_transaksi' => $row['total_transaksi'],
                'jasa_simpanan' => $row['jasa_simpanan'],
                'jasa_pinjaman' => $row['jasa_pinjaman'],
                'jasa_transaksi' => $row['jasa_transaksi'],
                'total_shu' => $row['total_shu'],
                'status_bayar' => 'belum',
            ]);
        }
        DB::table('shu_periode')->where('id', $id)->update([
            'status' => 'dihitung',
            'snapshot_hash' => $sim['hash'],
            'alokasi_json' => json_encode($sim['alokasi'], JSON_THROW_ON_ERROR),
            'dihitung_at' => now(),
        ]);
        $this->support->audit($id, $actor, 'hitung', ['hash' => $sim['hash'], 'alokasi' => $sim['alokasi']]);

        return $id;
    }

    /** @param  array<string, mixed>  $input */
    private function approve(array $input, User $actor): int
    {
        $id = (int) ($input['periode_id'] ?? 0);
        $period = $this->support->period($id);
        if ($period->status !== 'dihitung') {
            throw new DomainException('Hitung simulasi sebelum pengesahan.');
        }
        if ($period->tanggal_selesai >= today()->toDateString()) {
            throw new DomainException('Tahun buku belum berakhir. Pengesahan hanya setelah periode selesai.');
        }
        $ref = trim((string) ($input['referensi_rat'] ?? ''));
        if (mb_strlen($ref) < 5 || mb_strlen($ref) > 200 || empty($input['konfirmasi_rat'])) {
            throw new DomainException('Referensi keputusan RAT dan konfirmasi pemeriksaan wajib diisi.');
        }
        $sim = $this->support->simulate($period);
        if (! hash_equals((string) $period->snapshot_hash, $sim['hash'])) {
            throw new DomainException('Kontribusi sumber berubah. Hitung ulang simulasi dan tinjau sebelum mengesahkan.');
        }
        DB::table('shu_periode')->where('id', $id)->update([
            'status' => 'final',
            'referensi_rat' => $ref,
            'disahkan_oleh' => $actor->id,
            'disahkan_at' => now(),
        ]);
        $this->support->audit($id, $actor, 'sahkan', ['referensi_rat' => $ref, 'hash' => $sim['hash']]);

        return $id;
    }

    /** @param  array<string, mixed>  $input */
    private function pay(array $input, User $actor): int
    {
        $id = (int) ($input['periode_id'] ?? 0);
        $period = $this->support->period($id);
        if ($period->status !== 'final') {
            throw new DomainException('Pembayaran hanya untuk periode final yang telah disahkan.');
        }
        $row = DB::table('shu_anggota')->where('id', (int) ($input['shu_anggota_id'] ?? 0))->where('shu_periode_id', $id)->lockForUpdate()->first();
        if (! $row || $row->status_bayar !== 'belum' || (int) $row->total_shu <= 0) {
            throw new DomainException('Penerima tidak ditemukan, sudah dibayar, atau hak SHU nol.');
        }
        $method = (string) ($input['metode'] ?? '');
        if (! in_array($method, ['cash', 'transfer'], true)) {
            throw new DomainException('Pilih metode Cash atau Transfer.');
        }
        $ref = trim((string) ($input['referensi'] ?? ''));
        if (mb_strlen($ref) < 3 || mb_strlen($ref) > 100) {
            throw new DomainException('Referensi pembayaran atau penerima wajib diisi (3–100 karakter).');
        }
        $nonce = (string) ($input['payment_nonce'] ?? '');
        if (! preg_match('/^[a-f0-9]{32}$/D', $nonce)) {
            throw new DomainException('Permintaan pembayaran tidak valid. Muat ulang halaman.');
        }
        $proof = (string) ($input['bukti'] ?? '');
        if ($proof === '') {
            throw new DomainException('Bukti pembayaran SHU wajib diunggah.');
        }
        $txId = $this->support->cashOut((int) $row->anggota_id, (int) $row->total_shu, 'Pembayaran SHU '.$period->tahun, $actor);
        $payId = DB::table('shu_pembayaran')->insertGetId([
            'shu_anggota_id' => $row->id,
            'jumlah' => $row->total_shu,
            'metode' => $method,
            'bukti' => $proof,
            'referensi' => $ref,
            'nonce' => $nonce,
            'user_id' => $actor->id,
            'transaksi_id' => $txId,
            'created_at' => now(),
        ]);
        DB::table('shu_anggota')->where('id', $row->id)->update([
            'status_bayar' => 'sudah',
            'tanggal_bayar' => now(),
            'pembayaran_id' => $payId,
        ]);
        $this->support->audit($id, $actor, 'bayar', ['pembayaran' => $payId, 'anggota' => $row->anggota_id, 'jumlah' => (int) $row->total_shu, 'metode' => $method, 'transaksi' => $txId]);

        return $id;
    }

    /** @param  array<string, mixed>  $input */
    private function reverse(array $input, User $actor): int
    {
        $id = (int) ($input['periode_id'] ?? 0);
        $this->support->period($id);
        $reason = trim((string) ($input['alasan'] ?? ''));
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            throw new DomainException('Alasan koreksi wajib 10–500 karakter.');
        }
        if (empty($input['uang_kembali'])) {
            throw new DomainException('Konfirmasi dana benar-benar kembali ke koperasi sebelum membalik pembayaran.');
        }
        $payment = DB::table('shu_pembayaran as b')
            ->join('shu_anggota as a', 'a.id', '=', 'b.shu_anggota_id')
            ->where('b.id', (int) ($input['pembayaran_id'] ?? 0))
            ->where('a.shu_periode_id', $id)
            ->select(['b.*', 'a.anggota_id', 'a.pembayaran_id as current_payment_id'])
            ->lockForUpdate()
            ->first();
        if (! $payment || $payment->dibatalkan_at || (int) $payment->current_payment_id !== (int) $payment->id) {
            throw new DomainException('Pembayaran sudah dikoreksi atau tidak ditemukan.');
        }
        $txId = $this->support->cashIn((int) $payment->anggota_id, (int) $payment->jumlah, 'Pengembalian SHU periode #'.$id.' pembayaran #'.$payment->id, $actor);
        DB::table('shu_pembayaran')->where('id', $payment->id)->update([
            'dibatalkan_at' => now(),
            'dibatalkan_oleh' => $actor->id,
            'alasan_batal' => $reason,
            'transaksi_balik_id' => $txId,
        ]);
        DB::table('shu_anggota')->where('id', $payment->shu_anggota_id)->update([
            'status_bayar' => 'belum',
            'tanggal_bayar' => null,
            'pembayaran_id' => null,
        ]);
        $this->support->audit($id, $actor, 'balik', ['pembayaran' => $payment->id, 'alasan' => $reason, 'transaksi' => $txId]);

        return $id;
    }
}
