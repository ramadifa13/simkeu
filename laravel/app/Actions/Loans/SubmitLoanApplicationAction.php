<?php

namespace App\Actions\Loans;

use App\Actions\Members\GenerateMemberCodes;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SubmitLoanApplicationAction
{
    /** @var array<int, float> */
    private const PAWN_TENOR_RATES = [1 => 15.0, 2 => 15.0, 3 => 15.0, 4 => 15.0, 5 => 15.0, 6 => 15.0];

    public function __construct(
        private readonly LoanCalculator $calculator,
        private readonly GenerateMemberCodes $memberCodes,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(array $attributes, User $actor): int
    {
        return DB::transaction(function () use ($attributes, $actor): int {
            $member = DB::table('anggota')->where('id', $attributes['anggota_id'])->lockForUpdate()->first();
            $product = DB::table('jenis_pinjaman')->where('id', $attributes['jenis_pinjaman_id'])->lockForUpdate()->first();

            if (! $member || ! in_array($member->status, ['aktif', 'nonaktif'], true)) {
                throw new DomainException('Anggota wajib dipilih.');
            }
            if (! $product || $product->status !== 'aktif') {
                throw new DomainException('Jenis pinjaman tidak tersedia.');
            }

            if ($member->status === 'nonaktif') {
                $this->reactivateMember((int) $member->id);
                $member = DB::table('anggota')->where('id', $member->id)->lockForUpdate()->first();
            }

            $requiresCollateral = (bool) $product->memerlukan_jaminan;
            $amount = round((float) $attributes['jumlah_pinjaman'], 2);
            $tenor = (int) $attributes['tenor'];
            $frequency = (string) $attributes['frekuensi'];
            $priority = (bool) ($attributes['fasilitas_prioritas'] ?? false);
            $applicationDate = (string) ($attributes['tanggal_pengajuan'] ?? today()->toDateString());

            if ($amount <= 0 || $amount > (float) $product->maksimal_pinjaman) {
                throw new DomainException('Nominal pinjaman tidak sesuai batas produk.');
            }
            if ($applicationDate > today()->toDateString()) {
                throw new DomainException('Tanggal pengajuan tidak boleh di masa depan.');
            }
            if (blank($attributes['tujuan_pinjaman'] ?? null)) {
                throw new DomainException('Tujuan pinjaman wajib diisi.');
            }

            $allowedFrequencies = $requiresCollateral
                ? ['bulanan', 'mingguan', 'dua_mingguan']
                : array_values(array_filter(array_map('trim', explode(',', (string) $product->frekuensi_diizinkan))));
            if (! in_array($frequency, ['bulanan', 'mingguan', 'dua_mingguan'], true) || ! in_array($frequency, $allowedFrequencies, true)) {
                throw new DomainException('Frekuensi angsuran tidak diizinkan untuk produk ini.');
            }

            if ($requiresCollateral) {
                if ($tenor < 1 || $tenor > 6 || ! array_key_exists($tenor, self::PAWN_TENOR_RATES)) {
                    throw new DomainException('Tenor pinjaman jaminan harus antara 1 sampai 6 bulan.');
                }
                if ($this->calculator->weeklyPawnFee($amount) === null) {
                    throw new DomainException('Nominal pinjaman gadai harus kelipatan Rp 1 juta.');
                }
                if ($priority) {
                    throw new DomainException('Fasilitas prioritas hanya untuk pinjaman tanpa jaminan sampai Rp 1 juta.');
                }
                $interest = $this->calculator->calculatePawn($amount, self::PAWN_TENOR_RATES[$tenor], $tenor, $frequency)['bunga_persen'];
                $dayOfWeek = null;
                $shuCategory = 'anggota';
                $groupLeaderId = null;
            } else {
                if (! $requiresCollateral && $frequency === 'dua_mingguan' && $tenor !== 6) {
                    throw new DomainException('Angsuran 2 minggu sekali tanpa jaminan hanya tersedia untuk tenor 6 bulan.');
                }
                $tariff = DB::table('jenis_pinjaman_tarif')
                    ->where('jenis_pinjaman_id', $product->id)
                    ->where('tenor_bulan', $tenor)
                    ->value('bunga');
                if ($tariff === null) {
                    throw new DomainException('Tenor yang dipilih belum memiliki tarif bunga pada master produk.');
                }
                $interest = $frequency === 'dua_mingguan' ? 45.0 : (float) $tariff;
                if (! isset($attributes['hari_angsuran']) || (int) $attributes['hari_angsuran'] < 1 || (int) $attributes['hari_angsuran'] > 7) {
                    throw new DomainException('Hari angsuran wajib dipilih untuk pinjaman tanpa jaminan.');
                }
                $dayOfWeek = (int) $attributes['hari_angsuran'];
                [$shuCategory, $groupLeaderId] = $this->validateShuProfile(
                    (int) $member->id,
                    (string) ($attributes['kategori_shu'] ?? $member->kategori_shu ?? 'anggota'),
                    isset($attributes['ketua_kelompok_id']) ? (int) $attributes['ketua_kelompok_id'] : (int) ($member->ketua_kelompok_id ?? 0),
                );
                $this->validatePriority((int) $member->id, $amount, $priority);
            }

            $this->assertNoOpenLoanOrApplication((int) $member->id, $requiresCollateral);

            $collateral = $requiresCollateral ? $this->composeCollateral($attributes) : null;

            if ($requiresCollateral && Schema::hasColumn('anggota', 'kode_anggota_jaminan') && blank($member->kode_anggota_jaminan ?? null)) {
                $badge = $this->memberCodes->nextCollateralBadge();
                DB::table('anggota')->where('id', $member->id)->update(['kode_anggota_jaminan' => $badge]);
            }

            if (! $requiresCollateral) {
                $isAgtj = str_starts_with((string) ($member->kode_anggota ?? ''), 'AGTJ');
                if ($isAgtj) {
                    $docs = is_array($attributes['member_documents'] ?? null) ? $attributes['member_documents'] : [];
                    $kk = $docs['foto_kk'] ?? null;
                    $spouse = $docs['foto_ktp_pasangan'] ?? null;
                    if (Schema::hasColumn('anggota', 'foto_kk') && blank($member->foto_kk ?? null) && blank($kk)) {
                        throw new DomainException('Foto KK wajib diunggah untuk anggota AGTJ.');
                    }
                    if (Schema::hasColumn('anggota', 'foto_ktp_pasangan') && blank($member->foto_ktp_pasangan ?? null) && blank($spouse)) {
                        throw new DomainException('Foto KTP pasangan wajib diunggah untuk anggota AGTJ.');
                    }
                }
            }

            if (! empty($attributes['member_documents']) && is_array($attributes['member_documents'])) {
                $documentUpdate = array_filter([
                    'foto_kk' => $attributes['member_documents']['foto_kk'] ?? null,
                    'foto_ktp_pasangan' => $attributes['member_documents']['foto_ktp_pasangan'] ?? null,
                ], fn ($path): bool => filled($path));
                if ($documentUpdate !== []) {
                    DB::table('anggota')->where('id', $member->id)->update($documentUpdate);
                }
            }

            $payload = [
                'kode_pengajuan' => $this->code(),
                'anggota_id' => $member->id,
                'jenis_pinjaman_id' => $product->id,
                'jumlah_pinjaman' => $amount,
                'tenor' => $tenor,
                'bunga' => $interest,
                'skema_bunga' => 'flat',
                'frekuensi' => $frequency,
                'tujuan_pinjaman' => trim((string) $attributes['tujuan_pinjaman']),
                'status' => 'menunggu',
                'user_id' => $actor->id,
                'tanggal_pengajuan' => $applicationDate.' '.now()->format('H:i:s'),
                'hari_angsuran' => $dayOfWeek,
                'kategori_shu' => $shuCategory,
                'ketua_kelompok_id' => $groupLeaderId,
            ];
            if (Schema::hasColumn('pengajuan_pinjaman', 'fasilitas_prioritas')) {
                $payload['fasilitas_prioritas'] = $priority ? 1 : 0;
            }

            $applicationId = DB::table('pengajuan_pinjaman')->insertGetId($payload);

            if (is_array($collateral)) {
                DB::table('jaminan_pinjaman')->insert([
                    'pengajuan_id' => $applicationId,
                    'jenis_barang' => $collateral['jenis_barang'],
                    'identitas_barang' => $collateral['identitas_barang'],
                    'deskripsi_barang' => $collateral['deskripsi_barang'],
                    'nilai_taksiran' => 0,
                    'status_jaminan' => 'dititipkan',
                ]);
            }

            return $applicationId;
        }, 3);
    }

    /** @return array{0:string,1:?int} */
    private function validateShuProfile(int $memberId, string $category, int $leaderId): array
    {
        $category = in_array($category, ['anggota', 'ketua_kelompok'], true) ? $category : 'anggota';
        $leaderId = $category === 'anggota' ? $leaderId : 0;
        if ($category === 'anggota' && $leaderId > 0) {
            if ($leaderId === $memberId) {
                throw new DomainException('Anggota tidak dapat memilih dirinya sebagai ketua kelompok.');
            }
            $exists = DB::table('anggota')->where('id', $leaderId)->where('kategori_shu', 'ketua_kelompok')->exists();
            if (! $exists) {
                throw new DomainException('Ketua kelompok tidak ditemukan atau kategorinya belum Ketua Kelompok.');
            }
        }

        return [$category, $leaderId > 0 ? $leaderId : null];
    }

    private function validatePriority(int $memberId, float $amount, bool $requested): void
    {
        if (! $requested) {
            return;
        }
        if ($amount > 1_000_000) {
            throw new DomainException('Fasilitas prioritas hanya tanpa jaminan sampai Rp 1 juta.');
        }
        if (! Schema::hasTable('layanan_prioritas')) {
            throw new DomainException('Fasilitas prioritas belum disetujui atau dua pelunasan terakhir tidak lancar.');
        }
        $approved = DB::table('layanan_prioritas')->where('anggota_id', $memberId)->where('status', 'disetujui')->exists();
        if (! $approved || ! $this->priorityEligible($memberId)) {
            throw new DomainException('Fasilitas prioritas belum disetujui atau dua pelunasan terakhir tidak lancar.');
        }
    }

    private function priorityEligible(int $memberId): bool
    {
        $query = DB::table('pinjaman as p')
            ->where('p.anggota_id', $memberId)
            ->where('p.status', 'lunas')
            ->whereExists(fn ($builder) => $builder->selectRaw('1')->from('angsuran as ax')->whereColumn('ax.pinjaman_id', 'p.id'));
        if (Schema::hasColumn('pinjaman', 'tanggal_lunas')) {
            $query->orderByDesc('p.tanggal_lunas');
        }
        $loans = $query->orderByDesc('p.id')->limit(2)->get(['p.id']);

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

    private function assertNoOpenLoanOrApplication(int $memberId, bool $requiresCollateral): void
    {
        if (! Schema::hasTable('pinjaman')) {
            return;
        }

        $sameCategory = DB::table('pinjaman as p')->join('jenis_pinjaman as jp', 'jp.id', '=', 'p.jenis_pinjaman_id')
            ->where('p.anggota_id', $memberId)->where('p.status', '<>', 'lunas')
            ->where('jp.memerlukan_jaminan', $requiresCollateral)->exists();
        if ($sameCategory) {
            throw new DomainException('Anggota masih memiliki pinjaman pada kategori yang sama dan belum lunas.');
        }

        $inProcess = DB::table('pengajuan_pinjaman as pp')->join('jenis_pinjaman as jp', 'jp.id', '=', 'pp.jenis_pinjaman_id')
            ->where('pp.anggota_id', $memberId)->whereIn('pp.status', ['menunggu', 'disetujui', 'dicairkan'])
            ->where('jp.memerlukan_jaminan', $requiresCollateral)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('pinjaman as completed')->whereColumn('completed.pengajuan_id', 'pp.id')->where('completed.status', 'lunas'))
            ->exists();
        if ($inProcess) {
            throw new DomainException('Anggota masih memiliki pengajuan pada kategori yang sama yang sedang diproses.');
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{jenis_barang:string,identitas_barang:string,deskripsi_barang:string}
     */
    private function composeCollateral(array $attributes): array
    {
        $category = (string) ($attributes['kategori_jaminan'] ?? '');
        if ($category === '' && is_array($attributes['jaminan'] ?? null)) {
            $legacy = $attributes['jaminan'];
            $jenis = $this->filledText($legacy['jenis_barang'] ?? null, 'Jenis barang');
            $identitas = $this->filledText($legacy['identitas_barang'] ?? null, 'Identitas barang');

            return [
                'jenis_barang' => $jenis,
                'identitas_barang' => $identitas,
                'deskripsi_barang' => trim((string) ($legacy['deskripsi_barang'] ?? '')),
            ];
        }

        return match ($category) {
            'hp' => [
                'jenis_barang' => 'HP - '.$this->filledText($attributes['hp_jenis'] ?? null, 'Jenis HP'),
                'identitas_barang' => 'IMEI: '.$this->filledText($attributes['hp_imei'] ?? null, 'IMEI'),
                'deskripsi_barang' => implode("\n", [
                    'Merk/tipe: '.$this->filledText($attributes['hp_merk_tipe'] ?? null, 'Merk/tipe HP'),
                    'Warna: '.$this->filledText($attributes['hp_warna'] ?? null, 'Warna HP'),
                    'Nomor seri: '.$this->filledText($attributes['hp_nomor_seri'] ?? null, 'Nomor seri HP'),
                    'Nomor model: '.$this->filledText($attributes['hp_nomor_model'] ?? null, 'Nomor model HP'),
                    'Kelengkapan: '.$this->filledText($attributes['hp_kelengkapan'] ?? null, 'Kelengkapan HP'),
                    'Kondisi: '.$this->filledText($attributes['hp_kondisi'] ?? null, 'Kondisi HP'),
                ]),
            ],
            'sepeda_motor' => [
                'jenis_barang' => 'Sepeda Motor - '.$this->filledText($attributes['motor_jenis_kendaraan'] ?? null, 'Jenis kendaraan'),
                'identitas_barang' => 'Nopol: '.$this->filledText($attributes['motor_nopol'] ?? null, 'Nomor polisi').'; BPKB: '.$this->filledText($attributes['motor_no_bpkb'] ?? null, 'Nomor BPKB'),
                'deskripsi_barang' => implode("\n", [
                    'Merk/tipe: '.$this->filledText($attributes['motor_merk_tipe'] ?? null, 'Merk/tipe motor'),
                    'Tahun: '.$this->filledText($attributes['motor_tahun'] ?? null, 'Tahun motor'),
                    'No rangka: '.$this->filledText($attributes['motor_no_rangka'] ?? null, 'No rangka'),
                    'No mesin: '.$this->filledText($attributes['motor_no_mesin'] ?? null, 'No mesin'),
                    'Warna: '.$this->filledText($attributes['motor_warna'] ?? null, 'Warna motor'),
                    'Atas nama BPKB: '.$this->filledText($attributes['motor_atas_nama_bpkb'] ?? null, 'Atas nama BPKB'),
                    'Kondisi: '.$this->filledText($attributes['motor_kondisi'] ?? null, 'Kondisi motor'),
                ]),
            ],
            'emas' => [
                'jenis_barang' => 'Emas - '.$this->filledText($attributes['emas_jenis_barang'] ?? null, 'Jenis barang emas'),
                'identitas_barang' => 'Surat/nota: '.$this->filledText($attributes['emas_surat_nota'] ?? null, 'Surat/nota emas'),
                'deskripsi_barang' => implode("\n", [
                    'Berat: '.$this->filledText($attributes['emas_berat'] ?? null, 'Berat emas'),
                    'Kadar: '.$this->filledText($attributes['emas_kadar'] ?? null, 'Kadar emas'),
                    'Bentuk/ciri: '.$this->filledText($attributes['emas_bentuk_ciri'] ?? null, 'Bentuk/ciri emas'),
                ]),
            ],
            default => throw new DomainException('Kategori jaminan wajib dipilih: HP, sepeda motor, atau emas.'),
        };
    }

    private function filledText(mixed $value, string $label): string
    {
        $text = trim((string) $value);
        if ($text === '' || in_array($text, ['-', '–', '—', '.'], true)) {
            throw new DomainException($label.' jaminan wajib diisi dengan nilai yang valid.');
        }

        return $text;
    }

    private function reactivateMember(int $memberId): void
    {
        $payload = ['status' => 'aktif'];
        if (Schema::hasColumn('anggota', 'nonaktif_jadwal')) {
            $payload['nonaktif_jadwal'] = null;
        }
        DB::table('anggota')->where('id', $memberId)->update($payload);

        $userId = DB::table('anggota')->where('id', $memberId)->value('user_id');
        if ($userId && Schema::hasTable('users')) {
            DB::table('users')->where('id', $userId)->where('role', 'anggota')->update(['status' => 'aktif']);
        }
    }

    private function code(): string
    {
        return sprintf('PJG-%s-%03d', now()->format('ymdHis'), random_int(1, 999));
    }
}
