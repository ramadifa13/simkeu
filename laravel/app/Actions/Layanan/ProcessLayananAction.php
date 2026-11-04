<?php

namespace App\Actions\Layanan;

use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Ports native prosesLayanan / prosesLayananLanjutan for Phase 8. */
class ProcessLayananAction
{
    public function __construct(private readonly LayananSupport $support) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(int $loanId, string $action, array $input, User $actor): string
    {
        return DB::transaction(function () use ($loanId, $action, $input, $actor): string {
            $loan = $this->support->loan($loanId);
            $detail = match ($action) {
                'lapor_macet' => $this->reportDefault($loan, $input, $actor),
                'keputusan_macet' => $this->decideDefault($loan, $input, $actor),
                'reschedule' => $this->reschedule($loan, $input, $actor),
                'eksekusi' => $this->executeCollateral($loan, $input, $actor),
                'diskon_denda' => $this->discountPenalty($loan, $input, $actor),
                'approve_denda' => $this->approvePenaltyPayment($loan, $input, $actor),
                'ajukan_hapus_denda' => $this->requestWriteOff($loan, $input, $actor),
                'putuskan_hapus_denda' => $this->decideWriteOff($loan, $input, $actor),
                'prioritas' => $this->requestPriority($loan, $input, $actor),
                'koreksi_prioritas', 'approve_prioritas', 'tolak_prioritas' => $this->reviewPriority($loan, $action, $input, $actor),
                'jual_jaminan' => $this->sellCollateral($loan, $input, $actor),
                'kembalikan_lebih' => $this->returnSurplus($loan, $input, $actor),
                'bayar_bonus' => $this->payBonus($loan, $input, $actor),
                'repeat_order' => $this->repeatOrder($loan, $input, $actor),
                default => throw new DomainException('Tindakan layanan tidak dikenal.'),
            };
            $this->support->audit((int) $loan->id, $actor, $action, $detail);

            return $detail;
        }, 3);
    }

    /** @param  array<string, mixed>  $input */
    private function reportDefault(object $loan, array $input, User $actor): string
    {
        if (! Schema::hasTable('layanan_laporan_macet')) {
            throw new DomainException('Tabel laporan macet belum tersedia.');
        }
        $earliestDue = DB::table('angsuran')->where('pinjaman_id', $loan->id)->where('status', '<>', 'lunas')->min('tanggal_jatuh_tempo');
        $days = ($earliestDue && now()->parse($earliestDue)->startOfDay()->lt(today()))
            ? (int) now()->parse($earliestDue)->startOfDay()->diffInDays(today())
            : 0;
        if ($loan->status === 'lunas' || $days < 1) {
            throw new DomainException('Laporan hanya untuk nasabah yang terlambat.');
        }
        $note = $this->support->note((string) ($input['catatan'] ?? ''));
        if (DB::table('layanan_laporan_macet')->where('pinjaman_id', $loan->id)->where('status', 'menunggu')->exists()) {
            throw new DomainException('Masih ada laporan menunggu keputusan Admin.');
        }
        $remaining = $this->support->remainingDebt((int) $loan->id);
        DB::table('layanan_laporan_macet')->insert([
            'pinjaman_id' => $loan->id,
            'user_id' => $actor->id,
            'hari_telat' => $days,
            'sisa_hutang' => $remaining['total'],
            'catatan' => $note,
            'status' => 'menunggu',
            'created_at' => now(),
        ]);

        return $note;
    }

    /** @param  array<string, mixed>  $input */
    private function decideDefault(object $loan, array $input, User $actor): string
    {
        $this->support->requireAdmin($actor);
        $note = $this->support->note((string) ($input['catatan'] ?? ''));
        $decision = (string) ($input['keputusan'] ?? '');
        if (! in_array($decision, ['ditindaklanjuti', 'selesai', 'ditolak'], true)) {
            throw new DomainException('Keputusan tidak valid.');
        }
        $updated = DB::table('layanan_laporan_macet')
            ->where('id', (int) ($input['laporan_id'] ?? 0))
            ->where('pinjaman_id', $loan->id)
            ->where('status', 'menunggu')
            ->update(['status' => $decision, 'keputusan' => $note, 'admin_id' => $actor->id, 'diputuskan_at' => now()]);
        if (! $updated) {
            throw new DomainException('Laporan tidak ditemukan atau sudah diputuskan.');
        }

        return $note;
    }

    /** @param  array<string, mixed>  $input */
    private function reschedule(object $loan, array $input, User $actor): string
    {
        $this->support->requireAdmin($actor);
        $note = $this->support->note((string) ($input['catatan'] ?? ''));
        $date = (string) ($input['tanggal'] ?? '');
        if ($loan->status === 'lunas' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date <= today()->toDateString()) {
            throw new DomainException('Isi jatuh tempo baru setelah hari ini dan alasan perpanjangan.');
        }
        $old = DB::table('angsuran')->where('pinjaman_id', $loan->id)->where('status', '<>', 'lunas')->min('tanggal_jatuh_tempo');
        if (! $old || $date <= $old) {
            throw new DomainException('Tanggal baru harus setelah jatuh tempo lama.');
        }
        $shift = (int) now()->parse($old)->diffInDays(now()->parse($date));
        DB::table('angsuran')->where('pinjaman_id', $loan->id)->where('status', '<>', 'lunas')
            ->update([
                'tanggal_jatuh_tempo' => DB::raw("DATE_ADD(tanggal_jatuh_tempo, INTERVAL {$shift} DAY)"),
                'denda_manual' => 1,
                'status' => 'belum_bayar',
            ]);
        $maxDue = DB::table('angsuran')->where('pinjaman_id', $loan->id)->max('tanggal_jatuh_tempo');
        DB::table('pinjaman')->where('id', $loan->id)->update(['jatuh_tempo' => $maxDue]);

        return 'Jadwal digeser '.$shift.' hari. '.$note;
    }

    /** @param  array<string, mixed>  $input */
    private function executeCollateral(object $loan, array $input, User $actor): string
    {
        $this->support->requireAdmin($actor);
        $note = $this->support->note((string) ($input['catatan'] ?? ''));
        if ($loan->status === 'lunas' || ! $loan->memerlukan_jaminan) {
            throw new DomainException('Eksekusi membutuhkan pinjaman belum lunas dengan jaminan.');
        }
        $updated = DB::table('jaminan_pinjaman')->where('pinjaman_id', $loan->id)->where('status_jaminan', 'dititipkan')->update(['status_jaminan' => 'dilelang']);
        if (! $updated) {
            throw new DomainException('Jaminan tidak tersedia untuk dieksekusi.');
        }

        return 'Keputusan eksekusi jaminan: '.$note;
    }

    /** @param  array<string, mixed>  $input */
    private function discountPenalty(object $loan, array $input, User $actor): string
    {
        $this->support->requireAdmin($actor);
        $note = $this->support->note((string) ($input['catatan'] ?? ''));
        if ($loan->status === 'lunas' || ! $this->support->pemutihanEnabled()) {
            throw new DomainException('Pemutihan dinonaktifkan atau pinjaman sudah lunas.');
        }
        $installment = DB::table('angsuran')->where('id', (int) ($input['angsuran_id'] ?? 0))->where('pinjaman_id', $loan->id)->where('status', '<>', 'lunas')->lockForUpdate()->first();
        if (! $installment) {
            throw new DomainException('Angsuran tidak ditemukan.');
        }
        $final = round((float) ($input['jumlah'] ?? -1), 2);
        $paid = (float) DB::table('layanan_bayar_denda')->where('angsuran_id', $installment->id)->sum('jumlah');
        if ($final < $paid || $final > (float) $installment->denda) {
            throw new DomainException('Denda akhir harus antara jumlah yang sudah dibayar dan denda saat ini.');
        }
        DB::table('angsuran')->where('id', $installment->id)->update(['denda' => $final, 'denda_manual' => 1]);

        return 'Angsuran '.$installment->id.' denda awal '.$installment->denda.' akhir '.$final.'; '.$note;
    }

    /** @param  array<string, mixed>  $input */
    private function approvePenaltyPayment(object $loan, array $input, User $actor): string
    {
        $this->support->requireAdmin($actor);
        if (! Schema::hasColumn('layanan_bayar_denda', 'approved_by')) {
            throw new DomainException('Kolom persetujuan denda tidak tersedia.');
        }
        $updated = DB::table('layanan_bayar_denda')->where('id', (int) ($input['bayar_id'] ?? 0))->where('pinjaman_id', $loan->id)->whereNull('approved_by')->update(['approved_by' => $actor->id]);
        if (! $updated) {
            throw new DomainException('Pembayaran tidak ditemukan atau sudah disetujui.');
        }

        return 'Persetujuan pembayaran denda #'.((int) ($input['bayar_id'] ?? 0));
    }

    /** @param  array<string, mixed>  $input */
    private function requestWriteOff(object $loan, array $input, User $actor): string
    {
        if (! Schema::hasTable('layanan_pengajuan_hapus_denda')) {
            throw new DomainException('Tabel pengajuan hapus denda belum tersedia.');
        }
        $note = $this->support->note((string) ($input['catatan'] ?? ''));
        if ($loan->status === 'lunas') {
            throw new DomainException('Pengajuan memerlukan pinjaman belum lunas.');
        }
        $installmentId = (int) ($input['angsuran_id'] ?? 0);
        $ok = DB::table('angsuran')->where('id', $installmentId)->where('pinjaman_id', $loan->id)->where('status', '<>', 'lunas')->where('denda', '>', 0)->exists();
        if (! $ok) {
            throw new DomainException('Angsuran berdenda tidak ditemukan.');
        }
        DB::table('layanan_pengajuan_hapus_denda')->insert([
            'pinjaman_id' => $loan->id,
            'angsuran_id' => $installmentId,
            'user_id' => $actor->id,
            'alasan' => $note,
            'status' => 'menunggu',
            'aktif' => 1,
            'created_at' => now(),
        ]);

        return $note;
    }

    /** @param  array<string, mixed>  $input */
    private function decideWriteOff(object $loan, array $input, User $actor): string
    {
        $this->support->requireAdmin($actor);
        $row = DB::table('layanan_pengajuan_hapus_denda')->where('id', (int) ($input['pengajuan_id'] ?? 0))->where('pinjaman_id', $loan->id)->where('status', 'menunggu')->lockForUpdate()->first();
        if (! $row) {
            throw new DomainException('Pengajuan sudah diproses atau tidak ditemukan.');
        }
        $decision = (string) ($input['keputusan'] ?? '');
        if (! in_array($decision, ['setuju', 'tolak'], true)) {
            throw new DomainException('Keputusan tidak valid.');
        }
        $detail = $row->alasan;
        if ($decision === 'setuju') {
            if (! $this->support->pemutihanEnabled()) {
                throw new DomainException('Pemutihan sedang dinonaktifkan.');
            }
            $installment = DB::table('angsuran')->where('id', $row->angsuran_id)->where('status', '<>', 'lunas')->lockForUpdate()->first();
            if (! $installment) {
                throw new DomainException('Angsuran sudah lunas; pengajuan hanya dapat ditolak.');
            }
            $paid = (float) DB::table('layanan_bayar_denda')->where('angsuran_id', $row->angsuran_id)->sum('jumlah');
            DB::table('angsuran')->where('id', $row->angsuran_id)->update(['denda' => $paid, 'denda_manual' => 1]);
            $detail = 'Denda awal '.$installment->denda.' diputihkan '.((float) $installment->denda - $paid).' '.$row->alasan;
        }
        DB::table('layanan_pengajuan_hapus_denda')->where('id', $row->id)->update([
            'status' => $decision === 'setuju' ? 'disetujui' : 'ditolak',
            'aktif' => null,
            'admin_id' => $actor->id,
            'diputuskan_at' => now(),
        ]);

        return $detail;
    }

    /** @param  array<string, mixed>  $input */
    private function requestPriority(object $loan, array $input, User $actor): string
    {
        if (! Schema::hasTable('layanan_prioritas')) {
            throw new DomainException('Tabel prioritas belum tersedia.');
        }
        if ($loan->status !== 'lunas') {
            throw new DomainException('Pengajuan prioritas hanya untuk nasabah lunas.');
        }
        $note = $this->support->note((string) ($input['catatan'] ?? ''));
        $existing = DB::table('layanan_prioritas')->where('pinjaman_id', $loan->id)->value('id');
        if ($existing) {
            DB::table('layanan_prioritas')->where('pinjaman_id', $loan->id)->update([
                'anggota_id' => $loan->anggota_id,
                'user_id' => $actor->id,
                'status' => 'menunggu',
                'admin_id' => null,
                'catatan' => $note,
                'koreksi' => null,
                'koreksi_admin_id' => null,
                'koreksi_at' => null,
            ]);
        } else {
            DB::table('layanan_prioritas')->insert([
                'pinjaman_id' => $loan->id,
                'anggota_id' => $loan->anggota_id,
                'user_id' => $actor->id,
                'catatan' => $note,
                'status' => 'menunggu',
                'created_at' => now(),
            ]);
        }

        return $note;
    }

    /** @param  array<string, mixed>  $input */
    private function reviewPriority(object $loan, string $action, array $input, User $actor): string
    {
        $this->support->requireAdmin($actor);
        $note = $action === 'approve_prioritas' ? trim((string) ($input['catatan'] ?? '')) : $this->support->note((string) ($input['catatan'] ?? ''));
        if ($action === 'approve_prioritas' && ! $this->support->priorityEligible((int) $loan->anggota_id)) {
            throw new DomainException('Prioritas memerlukan dua pinjaman lunas terakhir tanpa keterlambatan.');
        }
        $status = match ($action) {
            'koreksi_prioritas' => 'pengecekan',
            'tolak_prioritas' => 'ditolak',
            default => 'disetujui',
        };
        $updated = DB::table('layanan_prioritas')
            ->where('pinjaman_id', $loan->id)
            ->whereIn('status', ['menunggu', 'pengecekan'])
            ->update([
                'status' => $status,
                'admin_id' => $actor->id,
                'koreksi' => $note !== '' ? $note : null,
                'koreksi_admin_id' => $actor->id,
                'koreksi_at' => now(),
            ]);
        if (! $updated) {
            throw new DomainException('Pengajuan prioritas tidak ditemukan atau sudah diproses.');
        }

        return $note !== '' ? $note : 'Prioritas '.$status;
    }

    /** @param  array<string, mixed>  $input */
    private function sellCollateral(object $loan, array $input, User $actor): string
    {
        $this->support->requireAdmin($actor);
        if (! Schema::hasTable('layanan_lelang')) {
            throw new DomainException('Tabel lelang belum tersedia.');
        }
        $note = $this->support->note((string) ($input['catatan'] ?? ''));
        if ($loan->status === 'lunas' || ! $loan->memerlukan_jaminan) {
            throw new DomainException('Penjualan memerlukan pinjaman belum lunas dengan jaminan.');
        }
        if (! DB::table('jaminan_pinjaman')->where('pinjaman_id', $loan->id)->where('status_jaminan', 'dilelang')->lockForUpdate()->exists()) {
            throw new DomainException('Eksekusi jaminan harus diputuskan terlebih dahulu.');
        }
        if (DB::table('layanan_lelang')->where('pinjaman_id', $loan->id)->exists()) {
            throw new DomainException('Penjualan sudah dicatat.');
        }
        $price = round((float) ($input['jumlah'] ?? 0), 2);
        $method = (string) ($input['metode'] ?? '');
        $proof = (string) ($input['bukti'] ?? '');
        if ($price <= 0 || ! in_array($method, ['cash', 'transfer'], true) || $proof === '') {
            throw new DomainException('Harga, metode, dan bukti penjualan wajib diisi.');
        }
        $remaining = $this->support->remainingDebt((int) $loan->id);
        $credit = min($price, $remaining['total']);
        $surplus = round($price - $credit, 2);
        $cashId = $this->support->cashIn((int) $loan->id, $price, 'Hasil penjualan jaminan - '.$loan->kode_pinjaman);
        $lelangId = DB::table('layanan_lelang')->insertGetId([
            'pinjaman_id' => $loan->id,
            'user_id' => $actor->id,
            'harga_jual' => $price,
            'kredit' => $credit,
            'kelebihan' => $surplus,
            'dikembalikan' => 0,
            'terpakai' => 0,
            'metode' => $method,
            'bukti' => $proof,
            'transaksi_id' => $cashId,
            'catatan' => $note,
            'created_at' => now(),
        ]);
        $rows = DB::table('angsuran')->where('pinjaman_id', $loan->id)->where('status', '<>', 'lunas')->orderBy('angsuran_ke')->lockForUpdate()->get();
        foreach ($rows as $row) {
            $paidPenalty = (float) DB::table('layanan_bayar_denda')->where('angsuran_id', $row->id)->sum('jumlah');
            $due = round((float) $row->jumlah_angsuran + max(0, (float) $row->denda - $paidPenalty), 2);
            $available = (float) DB::table('layanan_lelang')->where('id', $lelangId)->value('kredit');
            if ($available + 0.001 < $due) {
                break;
            }
            $use = round(min($available, $due), 2);
            DB::table('layanan_lelang')->where('id', $lelangId)->update([
                'kredit' => DB::raw('kredit - '.$use),
                'terpakai' => DB::raw('terpakai + '.$use),
            ]);
            if (Schema::hasTable('layanan_alokasi_lelang')) {
                DB::table('layanan_alokasi_lelang')->insert(['lelang_id' => $lelangId, 'angsuran_id' => $row->id, 'jumlah' => $use]);
            }
            DB::table('angsuran')->where('id', $row->id)->update(['status' => 'lunas', 'tanggal_bayar' => today()->toDateString()]);
            $remainingPrincipal = max(0, (float) DB::table('pinjaman')->where('id', $loan->id)->value('sisa_pokok') - (float) $row->pokok);
            DB::table('pinjaman')->where('id', $loan->id)->update(['sisa_pokok' => $remainingPrincipal]);
        }
        $this->support->markLoanPaidIfDone((int) $loan->id);
        $this->support->recalculateCash();

        return 'Harga jual '.$price.'; kelebihan milik nasabah '.$surplus.'; '.$note;
    }

    /** @param  array<string, mixed>  $input */
    private function returnSurplus(object $loan, array $input, User $actor): string
    {
        $this->support->requireAdmin($actor);
        $auction = DB::table('layanan_lelang')->where('pinjaman_id', $loan->id)->lockForUpdate()->first();
        if (! $auction || (float) $auction->kelebihan <= (float) $auction->dikembalikan) {
            throw new DomainException('Tidak ada kelebihan hasil penjualan untuk dikembalikan.');
        }
        $amount = round((float) $auction->kelebihan - (float) $auction->dikembalikan, 2);
        $proof = (string) ($input['bukti'] ?? '');
        if ($proof === '') {
            throw new DomainException('Bukti pengembalian wajib diunggah.');
        }
        $txId = $this->support->cashOut((int) $loan->anggota_id, $amount, 'Pengembalian kelebihan penjualan - '.$loan->kode_pinjaman, $actor);
        DB::table('layanan_lelang')->where('id', $auction->id)->update(['dikembalikan' => $auction->kelebihan]);
        if (Schema::hasTable('layanan_pembayaran')) {
            DB::table('layanan_pembayaran')->insert([
                'pinjaman_id' => $loan->id,
                'user_id' => $actor->id,
                'jenis' => 'kembali_lelang',
                'jumlah' => $amount,
                'metode' => $auction->metode,
                'bukti' => $proof,
                'created_at' => now(),
            ]);
        }

        return 'Kelebihan dikembalikan Rp '.$amount.'; transaksi '.$txId;
    }

    /** @param  array<string, mixed>  $input */
    private function payBonus(object $loan, array $input, User $actor): string
    {
        $this->support->requireAdmin($actor);
        if (! Schema::hasTable('layanan_bonus') || ! Schema::hasTable('layanan_pengaturan')) {
            throw new DomainException('Modul bonus belum tersedia.');
        }
        if ($loan->status !== 'lunas' || $loan->memerlukan_jaminan) {
            throw new DomainException('Bonus hanya untuk pinjaman lunas tanpa jaminan.');
        }
        $percent = (float) DB::table('layanan_pengaturan')->where('id', 1)->value('bonus_persen');
        $interest = (float) DB::table('angsuran')->where('pinjaman_id', $loan->id)->where('status', 'lunas')->sum('bunga');
        $discount = Schema::hasTable('layanan_pembayaran')
            ? (float) DB::table('layanan_pembayaran')->where('pinjaman_id', $loan->id)->whereNull('dibatalkan_at')->sum('bunga_diskon')
            : 0.0;
        $base = max(0, round($interest - $discount, 2));
        $nominal = round($base * $percent / 100, 2);
        if ($percent <= 0 || $nominal <= 0) {
            throw new DomainException('Atur persentase bonus terlebih dahulu; bunga yang diterima harus positif.');
        }
        if (DB::table('layanan_bonus')->where('pinjaman_id', $loan->id)->exists()) {
            throw new DomainException('Bonus pinjaman ini sudah dibagikan.');
        }
        $amount = round((float) ($input['jumlah'] ?? 0), 2);
        $method = (string) ($input['metode'] ?? '');
        $proof = (string) ($input['bukti'] ?? '');
        if (abs($amount - $nominal) > 0.001 || ! in_array($method, ['cash', 'transfer'], true) || $proof === '') {
            throw new DomainException('Nominal bonus harus sesuai perhitungan terbaru.');
        }
        $txId = $this->support->cashOut((int) $loan->anggota_id, $amount, 'Bonus loyalitas '.now()->format('Y').' - '.$loan->kode_pinjaman, $actor);
        DB::table('layanan_bonus')->insert([
            'pinjaman_id' => $loan->id,
            'anggota_id' => $loan->anggota_id,
            'tahun' => (int) now()->format('Y'),
            'bunga_dasar' => $base,
            'persen' => $percent,
            'jumlah' => $amount,
            'metode' => $method,
            'bukti' => $proof,
            'user_id' => $actor->id,
            'transaksi_id' => $txId,
            'created_at' => now(),
        ]);

        return 'Bonus '.$percent.'% x bunga '.$base.' = '.$amount;
    }

    /** @param  array<string, mixed>  $input */
    private function repeatOrder(object $loan, array $input, User $actor): string
    {
        $this->support->requireAdmin($actor);
        if (! Schema::hasTable('layanan_repeat_order')) {
            throw new DomainException('Tabel repeat order belum tersedia.');
        }
        if ($loan->status !== 'lunas') {
            throw new DomainException('Isi catatan tindak lanjut nasabah lunas.');
        }
        $note = $this->support->note((string) ($input['catatan'] ?? ''));
        DB::table('layanan_repeat_order')->updateOrInsert(
            ['pinjaman_id' => $loan->id],
            ['user_id' => $actor->id, 'catatan' => $note, 'created_at' => now()]
        );

        return $note;
    }

}
