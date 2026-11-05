<?php

namespace App\Actions\Loans;

use App\Actions\Cash\RecalculateCashLedgerAction;
use App\Models\User;
use App\Support\CashEntry;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DisburseLoanAction
{
    public function __construct(
        private readonly LoanCalculator $calculator,
        private readonly RecalculateCashLedgerAction $cashLedger,
        private readonly RefreshLoanPenaltiesAction $refreshPenalties,
    ) {}

    /** @return array{loan_id:int,kode_pinjaman:string,jumlah_cicilan:int} */
    public function handle(int $applicationId, string $disbursementDate, User $actor, array $documents = [], array $agreement = [], int $paidInstallments = 0): array
    {
        return DB::transaction(function () use ($applicationId, $disbursementDate, $actor, $documents, $agreement, $paidInstallments): array {
            $application = DB::table('pengajuan_pinjaman')->where('id', $applicationId)->lockForUpdate()->first();
            if (! $application || $application->status !== 'disetujui') {
                throw new DomainException('Hanya pengajuan disetujui yang dapat dicairkan.');
            }

            $product = Schema::hasTable('jenis_pinjaman')
                ? DB::table('jenis_pinjaman')->where('id', $application->jenis_pinjaman_id)->first()
                : null;
            $member = Schema::hasTable('anggota')
                ? DB::table('anggota')->where('id', $application->anggota_id)->first()
                : null;
            $application->nama_jenis = $product->nama_jenis ?? null;
            $application->memerlukan_jaminan = (bool) ($product->memerlukan_jaminan ?? false);
            $application->nama_lengkap = $member->nama_lengkap ?? null;
            $application->kode_anggota = $member->kode_anggota ?? null;
            $application->kode_anggota_jaminan = $member->kode_anggota_jaminan ?? null;
            $application->ktp = $member->ktp ?? null;
            $application->alamat = $member->alamat ?? null;
            $application->telepon = $member->telepon ?? null;
            $application->tempat_lahir = $member->tempat_lahir ?? null;
            $application->tanggal_lahir = $member->tanggal_lahir ?? null;
            if ($disbursementDate < substr((string) $application->tanggal_pengajuan, 0, 10)) {
                throw new DomainException('Tanggal pencairan tidak boleh sebelum tanggal pengajuan.');
            }
            if (Schema::hasTable('layanan_tunda_cair')) {
                $blocked = DB::table('layanan_tunda_cair')
                    ->where('pengajuan_id', $applicationId)
                    ->whereIn('status', ['menunggu', 'disetujui'])
                    ->exists();
                if ($blocked) {
                    throw new DomainException('Pengajuan masih memiliki permintaan tunda cair yang aktif.');
                }
            }

            $this->assertPriorityStillValid($application);

            DB::table('kas')->orderByDesc('id')->limit(1)->lockForUpdate()->get();
            if (DB::table('pinjaman')->where('pengajuan_id', $applicationId)->exists()) {
                throw new DomainException('Pinjaman untuk pengajuan ini sudah dibuat.');
            }

            $calculation = $this->calculator->calculate(
                (float) $application->jumlah_pinjaman,
                (float) $application->bunga,
                (int) $application->tenor,
                $application->frekuensi,
                $application->skema_bunga,
            );
            if ($paidInstallments < 0 || $paidInstallments > $calculation['jumlah_cicilan']) {
                throw new DomainException('Cicilan dimuka tidak valid.');
            }
            if ($paidInstallments > 0 && $disbursementDate === today()->toDateString()) {
                throw new DomainException('Cicilan dimuka hanya dapat dipakai untuk pencairan tanggal lampau.');
            }

            $startDate = substr((string) $application->tanggal_pengajuan, 0, 10) ?: $disbursementDate;
            $lastDueDate = $this->calculator->dueDate($startDate, $calculation['jumlah_cicilan'], $calculation['frekuensi']);
            $loanId = DB::table('pinjaman')->insertGetId([
                'pengajuan_id' => $application->id,
                'kode_pinjaman' => 'TMP-'.bin2hex(random_bytes(5)),
                'anggota_id' => $application->anggota_id,
                'jenis_pinjaman_id' => $application->jenis_pinjaman_id,
                'jumlah_pinjaman' => $calculation['pokok'],
                'sisa_pokok' => $calculation['pokok'],
                'tenor' => $calculation['tenor'],
                'bunga' => $calculation['bunga_persen'],
                'skema_bunga' => $calculation['skema'],
                'frekuensi' => $calculation['frekuensi'],
                'jumlah_cicilan' => $calculation['jumlah_cicilan'],
                'total_pinjaman' => $calculation['total'],
                'angsuran_per_bulan' => $calculation['angsuran'],
                'tanggal_pencairan' => $disbursementDate,
                'jatuh_tempo' => $lastDueDate,
                'dicairkan_oleh' => $actor->id,
                'status' => 'aktif',
                'kategori_shu' => $application->kategori_shu,
                'ketua_kelompok_id' => $application->ketua_kelompok_id,
            ]);

            $code = 'PJN-'.now()->format('Ymd').'-'.str_pad((string) $loanId, 4, '0', STR_PAD_LEFT);
            $loanUpdate = ['kode_pinjaman' => $code];
            if (Schema::hasColumn('pinjaman', 'foto_pencairan')) {
                $loanUpdate['foto_pencairan'] = $documents['foto_nasabah'] ?? null;
            }
            if (Schema::hasColumn('pinjaman', 'input_mundur')) {
                $loanUpdate['input_mundur'] = $disbursementDate < today()->toDateString();
            }
            DB::table('pinjaman')->where('id', $loanId)->update($loanUpdate);

            $paidPrincipal = 0.0;
            $scheduleRows = [];
            foreach ($calculation['rincian'] as $detail) {
                $isPaid = $detail['ke'] <= $paidInstallments;
                $paidPrincipal += $isPaid ? $detail['pokok'] : 0;
                $dueDate = $this->calculator->dueDate($startDate, $detail['ke'], $calculation['frekuensi']);
                DB::table('angsuran')->insert([
                    'pinjaman_id' => $loanId,
                    'angsuran_ke' => $detail['ke'],
                    'jumlah_angsuran' => $detail['jumlah'],
                    'pokok' => $detail['pokok'],
                    'bunga' => $detail['bunga'],
                    'denda' => 0,
                    'tanggal_jatuh_tempo' => $dueDate,
                    'tanggal_bayar' => $isPaid ? $dueDate : null,
                    'status' => $isPaid ? 'lunas' : 'belum_bayar',
                    'cara_bayar' => 'cicilan',
                    'keterlambatan_hari' => 0,
                ]);
                $scheduleRows[] = [
                    'angsuran_ke' => $detail['ke'],
                    'pokok' => $detail['pokok'],
                    'bunga' => $detail['bunga'],
                    'jumlah_angsuran' => $detail['jumlah'],
                    'tanggal_jatuh_tempo' => $dueDate,
                ];
            }

            if ($paidInstallments > 0) {
                $loanPayload = ['sisa_pokok' => max(0, $calculation['pokok'] - $paidPrincipal)];
                if (Schema::hasColumn('pinjaman', 'cicilan_dimuka')) {
                    $loanPayload['cicilan_dimuka'] = $paidInstallments;
                }
                DB::table('pinjaman')->where('id', $loanId)->update($loanPayload);
            }

            $collateral = DB::table('jaminan_pinjaman')->where('pengajuan_id', $applicationId)->first();
            $collateralUpdate = ['pinjaman_id' => $loanId];
            if (Schema::hasColumn('jaminan_pinjaman', 'foto_barang')) {
                $collateralUpdate['foto_barang'] = $documents['foto_barang_jaminan'] ?? null;
            }
            if (Schema::hasColumn('jaminan_pinjaman', 'foto_nasabah_barang')) {
                $collateralUpdate['foto_nasabah_barang'] = $documents['foto_nasabah_barang'] ?? null;
            }
            DB::table('jaminan_pinjaman')->where('pengajuan_id', $applicationId)->update($collateralUpdate);

            DB::table('kas')->orderByDesc('id')->limit(1)->lockForUpdate()->get();
            $cashBefore = (float) DB::table('kas')->selectRaw("COALESCE(SUM(CASE WHEN jenis = 'masuk' THEN jumlah ELSE -jumlah END),0) AS saldo")->value('saldo');
            if ($calculation['pokok'] - $cashBefore > 0.005) {
                throw new DomainException('Saldo kas tidak cukup untuk pencairan.');
            }
            DB::table('kas')->insert(CashEntry::attributes([
                'transaksi_id' => $loanId,
                'jenis' => 'keluar',
                'jumlah' => $calculation['pokok'],
                'saldo_sebelum' => $cashBefore,
                'saldo_sesudah' => $cashBefore - $calculation['pokok'],
                'keterangan' => 'Pencairan pinjaman '.$code,
                'tanggal' => $disbursementDate.' '.now()->format('H:i:s'),
            ], CashEntry::PINJAMAN_CAIR));
            DB::table('pengajuan_pinjaman')->where('id', $applicationId)->update(['status' => 'dicairkan']);

            if (Schema::hasTable('perjanjian_pinjaman') && $agreement !== []) {
                $this->storeAgreement(
                    $loanId,
                    $code,
                    $disbursementDate,
                    $application,
                    $calculation,
                    $scheduleRows,
                    $lastDueDate,
                    $collateral,
                    $documents,
                    $actor,
                    $agreement,
                );
            }

            $this->cashLedger->recalculateLocked();
            if (Schema::hasTable('jenis_pinjaman') && Schema::hasTable('denda_tarif')) {
                $this->refreshPenalties->handle($loanId, today()->toDateString());
            }

            return ['loan_id' => $loanId, 'kode_pinjaman' => $code, 'jumlah_cicilan' => $calculation['jumlah_cicilan']];
        }, 3);
    }

    private function assertPriorityStillValid(object $application): void
    {
        if (! Schema::hasColumn('pengajuan_pinjaman', 'fasilitas_prioritas') || empty($application->fasilitas_prioritas)) {
            return;
        }
        if ((bool) $application->memerlukan_jaminan || (float) $application->jumlah_pinjaman > 1_000_000) {
            throw new DomainException('Fasilitas prioritas hanya tanpa jaminan sampai Rp 1 juta.');
        }
        if (! Schema::hasTable('layanan_prioritas')) {
            throw new DomainException('Fasilitas prioritas belum disetujui atau dua pelunasan terakhir tidak lancar.');
        }
        $approved = DB::table('layanan_prioritas')
            ->where('anggota_id', $application->anggota_id)
            ->where('status', 'disetujui')
            ->exists();
        if (! $approved) {
            throw new DomainException('Fasilitas prioritas belum disetujui atau dua pelunasan terakhir tidak lancar.');
        }
    }

    /**
     * @param  list<array{angsuran_ke:int,pokok:float,bunga:float,jumlah_angsuran:float,tanggal_jatuh_tempo:string}>  $scheduleRows
     * @param  array<string, mixed>  $documents
     * @param  array<string, mixed>  $agreement
     */
    private function storeAgreement(
        int $loanId,
        string $code,
        string $disbursementDate,
        object $application,
        array $calculation,
        array $scheduleRows,
        string $lastDueDate,
        ?object $collateral,
        array $documents,
        User $actor,
        array $agreement,
    ): void {
        $cooperative = Schema::hasTable('pengaturan_koperasi')
            ? DB::table('pengaturan_koperasi')->orderBy('id')->first()
            : null;
        $settings = Schema::hasTable('pengaturan_perjanjian')
            ? DB::table('pengaturan_perjanjian')->where('id', 1)->first()
            : null;
        $isPawn = (bool) $application->memerlukan_jaminan;
        $memberCode = $isPawn
            ? ((string) ($application->kode_anggota_jaminan ?: $application->kode_anggota))
            : (string) $application->kode_anggota;

        $snapshot = [
            'koperasi' => [
                'nama' => $cooperative->nama_koperasi ?? config('app.name'),
                'alamat' => $cooperative->alamat ?? null,
                'telepon' => $cooperative->telepon ?? null,
            ],
            'anggota' => [
                'nama' => $application->nama_lengkap,
                'kode' => $memberCode,
                'nik' => $application->ktp ?? null,
                'alamat' => $application->alamat ?? null,
                'telepon' => $application->telepon ?? null,
                'tempat_lahir' => $application->tempat_lahir ?? null,
                'tanggal_lahir' => $application->tanggal_lahir ?? null,
            ],
            'pihak_pertama' => [
                'nama' => $settings->nama_pihak_pertama ?? ($cooperative->nama_koperasi ?? config('app.name')),
                'nik' => $settings->nik_pihak_pertama ?? null,
                'alamat' => $settings->alamat_pihak_pertama ?? null,
                'telepon' => $settings->telepon_pihak_pertama ?? null,
            ],
            'pinjaman' => [
                'id' => $loanId,
                'kode' => $code,
                'jenis' => $application->nama_jenis,
                'jumlah' => $calculation['pokok'],
                'tenor' => $calculation['tenor'],
                'bunga' => $calculation['bunga_persen'],
                'total' => $calculation['total'],
                'angsuran' => $calculation['angsuran'],
                'frekuensi' => $calculation['frekuensi'],
                'jumlah_cicilan' => $calculation['jumlah_cicilan'],
                'tanggal_pencairan' => $disbursementDate,
                'jatuh_tempo' => $lastDueDate,
                'tujuan' => $application->tujuan_pinjaman,
                'hari_angsuran' => $application->hari_angsuran,
            ],
            'jadwal_angsuran' => $scheduleRows,
            'jaminan' => [
                'ada' => $isPawn,
                'jenis' => $collateral->jenis_barang ?? null,
                'detail' => trim(($collateral->identitas_barang ?? '').' '.($collateral->deskripsi_barang ?? '')),
            ],
            'isi_perjanjian' => $settings->isi_lengkap ?? null,
            'penandatangan' => [
                'nasabah' => $application->nama_lengkap,
                'teller' => $actor->nama_lengkap ?? $actor->username ?? 'Teller',
                'admin' => $settings->nama_pihak_pertama ?? 'Admin',
                'jabatan_admin' => $settings->jabatan_admin ?? null,
                'saksi_1' => $agreement['nama_saksi_1'],
                'saksi_2' => $agreement['nama_saksi_2'],
            ],
            'foto' => [
                'pencairan' => $documents['foto_nasabah'] ?? null,
                'barang' => $documents['foto_barang_jaminan'] ?? null,
                'nasabah_barang' => $documents['foto_nasabah_barang'] ?? null,
            ],
            'pengajuan_id' => $application->id,
            'dibuat_pada' => now()->toDateTimeString(),
        ];

        $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $roman = [1 => 'I', 2 => 'II', 3 => 'III', 4 => 'IV', 5 => 'V', 6 => 'VI', 7 => 'VII', 8 => 'VIII', 9 => 'IX', 10 => 'X', 11 => 'XI', 12 => 'XII'];
        $month = (int) date('n', strtotime($disbursementDate));
        $number = str_pad((string) $loanId, 3, '0', STR_PAD_LEFT).'/SPHP/'.$roman[$month].'/'.date('Y', strtotime($disbursementDate));
        $hash = hash('sha256', $json.$agreement['ttd_nasabah'].$agreement['ttd_teller'].$agreement['ttd_admin'].$agreement['ttd_saksi_1'].$agreement['ttd_saksi_2']);

        $agreementId = DB::table('perjanjian_pinjaman')->insertGetId([
            'pinjaman_id' => $loanId,
            'nomor_perjanjian' => $number,
            'versi' => 'lengkap',
            'snapshot_json' => $json,
            'nama_saksi_1' => $agreement['nama_saksi_1'],
            'nama_saksi_2' => $agreement['nama_saksi_2'],
            'ttd_nasabah' => $agreement['ttd_nasabah'],
            'ttd_teller' => $agreement['ttd_teller'],
            'ttd_admin' => $agreement['ttd_admin'],
            'ttd_saksi_1' => $agreement['ttd_saksi_1'],
            'ttd_saksi_2' => $agreement['ttd_saksi_2'],
            'hash_dokumen' => $hash,
            'ditandatangani_at' => now(),
        ]);

        if (Schema::hasTable('lampiran_perjanjian')) {
            foreach (($agreement['lampiran'] ?? []) as $index => $path) {
                DB::table('lampiran_perjanjian')->insert([
                    'perjanjian_id' => $agreementId,
                    'file_path' => $path,
                    'urutan' => $index + 1,
                ]);
            }
        }
    }
}
