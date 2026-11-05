<?php

namespace Tests\Feature;

use App\Actions\Loans\LoanCalculator;
use App\Actions\Loans\SubmitLoanApplicationAction;
use App\Models\User;
use DomainException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LoanApplicationActionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('anggota', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('status');
            $table->string('kode_anggota')->nullable();
            $table->string('kode_anggota_jaminan')->nullable();
            $table->string('kategori_shu')->default('anggota');
            $table->unsignedInteger('ketua_kelompok_id')->nullable();
            $table->string('foto_kk')->nullable();
            $table->string('foto_ktp_pasangan')->nullable();
        });
        Schema::create('jenis_pinjaman', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('status');
            $table->decimal('maksimal_pinjaman', 15, 2);
            $table->integer('tenor_max');
            $table->decimal('bunga', 6, 2);
            $table->string('frekuensi_diizinkan');
            $table->boolean('memerlukan_jaminan');
        });
        Schema::create('jenis_pinjaman_tarif', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('jenis_pinjaman_id');
            $table->integer('tenor_bulan');
            $table->decimal('bunga', 6, 2);
        });
        Schema::create('pengajuan_pinjaman', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('kode_pengajuan');
            $table->unsignedInteger('anggota_id');
            $table->unsignedInteger('jenis_pinjaman_id');
            $table->decimal('jumlah_pinjaman', 15, 2);
            $table->integer('tenor');
            $table->decimal('bunga', 8, 4);
            $table->string('skema_bunga');
            $table->string('frekuensi');
            $table->text('tujuan_pinjaman')->nullable();
            $table->string('status');
            $table->unsignedInteger('user_id');
            $table->dateTime('tanggal_pengajuan');
            $table->integer('hari_angsuran')->nullable();
            $table->boolean('fasilitas_prioritas')->default(false);
            $table->string('kategori_shu');
            $table->unsignedInteger('ketua_kelompok_id')->nullable();
        });
        Schema::create('jaminan_pinjaman', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('pengajuan_id');
            $table->string('jenis_barang');
            $table->string('identitas_barang');
            $table->text('deskripsi_barang');
            $table->decimal('nilai_taksiran', 15, 2);
            $table->string('status_jaminan');
        });
        Schema::create('pinjaman', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('pengajuan_id')->nullable();
            $table->unsignedInteger('anggota_id');
            $table->unsignedInteger('jenis_pinjaman_id');
            $table->string('status');
        });
    }

    public function test_pawn_application_stores_effective_interest_and_composed_collateral(): void
    {
        $memberId = DB::table('anggota')->insertGetId(['status' => 'aktif', 'kode_anggota' => 'AGT0001']);
        $productId = DB::table('jenis_pinjaman')->insertGetId([
            'status' => 'aktif',
            'maksimal_pinjaman' => 5000000,
            'tenor_max' => 6,
            'bunga' => 15,
            'frekuensi_diizinkan' => 'mingguan,dua_mingguan',
            'memerlukan_jaminan' => true,
        ]);
        $actor = $this->makeUser('teller');
        $actor->id = 1;

        $applicationId = app(SubmitLoanApplicationAction::class)->handle([
            'anggota_id' => $memberId,
            'jenis_pinjaman_id' => $productId,
            'jumlah_pinjaman' => 2000000,
            'tenor' => 3,
            'frekuensi' => 'mingguan',
            'tujuan_pinjaman' => 'Modal usaha',
            'kategori_jaminan' => 'hp',
            'hp_jenis' => 'Smartphone',
            'hp_merk_tipe' => 'Samsung A14',
            'hp_warna' => 'Hitam',
            'hp_imei' => '356938035643809',
            'hp_nomor_seri' => 'SN-1',
            'hp_nomor_model' => 'SM-A145',
            'hp_kelengkapan' => 'HP + charger',
            'hp_kondisi' => 'Baik',
        ], $actor);

        $expected = app(LoanCalculator::class)->calculatePawn(2000000, 15, 3, 'mingguan');
        $this->assertDatabaseHas('pengajuan_pinjaman', [
            'id' => $applicationId,
            'status' => 'menunggu',
            'frekuensi' => 'mingguan',
            'bunga' => $expected['bunga_persen'],
        ]);
        $this->assertDatabaseHas('jaminan_pinjaman', [
            'pengajuan_id' => $applicationId,
            'jenis_barang' => 'HP - Smartphone',
            'identitas_barang' => 'IMEI: 356938035643809',
            'nilai_taksiran' => 0,
            'status_jaminan' => 'dititipkan',
        ]);
        $this->assertDatabaseHas('anggota', ['id' => $memberId]);
        $this->assertNotNull(DB::table('anggota')->where('id', $memberId)->value('kode_anggota_jaminan'));
        $this->assertGreaterThan(15, (float) DB::table('pengajuan_pinjaman')->where('id', $applicationId)->value('bunga'));
    }

    public function test_non_collateral_tenor_without_tariff_is_rejected(): void
    {
        $memberId = DB::table('anggota')->insertGetId(['status' => 'aktif', 'kode_anggota' => 'AGT0002']);
        $productId = DB::table('jenis_pinjaman')->insertGetId([
            'status' => 'aktif',
            'maksimal_pinjaman' => 5000000,
            'tenor_max' => 6,
            'bunga' => 15,
            'frekuensi_diizinkan' => 'bulanan,mingguan,dua_mingguan',
            'memerlukan_jaminan' => false,
        ]);
        DB::table('jenis_pinjaman_tarif')->insert(['jenis_pinjaman_id' => $productId, 'tenor_bulan' => 6, 'bunga' => 20]);
        $actor = $this->makeUser('teller');
        $actor->id = 1;

        $this->expectException(DomainException::class);
        app(SubmitLoanApplicationAction::class)->handle([
            'anggota_id' => $memberId,
            'jenis_pinjaman_id' => $productId,
            'jumlah_pinjaman' => 1000000,
            'tenor' => 3,
            'frekuensi' => 'bulanan',
            'hari_angsuran' => 1,
            'tujuan_pinjaman' => 'Keperluan sekolah',
        ], $actor);
    }

    public function test_non_collateral_biweekly_requires_six_month_tenor_and_a_payment_day(): void
    {
        $memberId = DB::table('anggota')->insertGetId(['status' => 'aktif', 'kode_anggota' => 'AGT0003']);
        $productId = DB::table('jenis_pinjaman')->insertGetId([
            'status' => 'aktif',
            'maksimal_pinjaman' => 5000000,
            'tenor_max' => 6,
            'bunga' => 15,
            'frekuensi_diizinkan' => 'bulanan,mingguan,dua_mingguan',
            'memerlukan_jaminan' => false,
        ]);
        DB::table('jenis_pinjaman_tarif')->insert(['jenis_pinjaman_id' => $productId, 'tenor_bulan' => 3, 'bunga' => 20]);
        $actor = $this->makeUser('teller');
        $actor->id = 1;

        $this->expectException(DomainException::class);
        app(SubmitLoanApplicationAction::class)->handle([
            'anggota_id' => $memberId,
            'jenis_pinjaman_id' => $productId,
            'jumlah_pinjaman' => 1000000,
            'tenor' => 3,
            'frekuensi' => 'dua_mingguan',
            'hari_angsuran' => 1,
            'tujuan_pinjaman' => 'Keperluan sekolah',
        ], $actor);
    }

    public function test_calculator_weekly_pawn_fee_matches_native_pattern(): void
    {
        $calculator = app(LoanCalculator::class);
        $this->assertNull($calculator->weeklyPawnFee(500000));
        $this->assertSame(10000, $calculator->weeklyPawnFee(1000000));
        $this->assertSame(15000, $calculator->weeklyPawnFee(2000000));
        $this->assertSame(20000, $calculator->weeklyPawnFee(3000000));
    }

    public function test_agt_with_collateral_badge_does_not_require_agtj_documents_for_non_pawn_loan(): void
    {
        $memberId = DB::table('anggota')->insertGetId([
            'status' => 'aktif',
            'kode_anggota' => 'AGT0009',
            'kode_anggota_jaminan' => 'AGTJ0009',
            'kategori_shu' => 'anggota',
        ]);
        $productId = DB::table('jenis_pinjaman')->insertGetId([
            'status' => 'aktif',
            'maksimal_pinjaman' => 5000000,
            'tenor_max' => 6,
            'bunga' => 15,
            'frekuensi_diizinkan' => 'bulanan',
            'memerlukan_jaminan' => false,
        ]);
        DB::table('jenis_pinjaman_tarif')->insert(['jenis_pinjaman_id' => $productId, 'tenor_bulan' => 6, 'bunga' => 20]);
        $actor = $this->makeUser('teller');
        $actor->id = 1;

        $applicationId = app(SubmitLoanApplicationAction::class)->handle([
            'anggota_id' => $memberId,
            'jenis_pinjaman_id' => $productId,
            'jumlah_pinjaman' => 1000000,
            'tenor' => 6,
            'frekuensi' => 'bulanan',
            'hari_angsuran' => 2,
            'tujuan_pinjaman' => 'Modal tambahan',
            'kategori_shu' => 'anggota',
        ], $actor);

        $this->assertDatabaseHas('pengajuan_pinjaman', [
            'id' => $applicationId,
            'anggota_id' => $memberId,
            'status' => 'menunggu',
            'hari_angsuran' => 2,
        ]);
    }

    public function test_agtj_member_without_documents_is_rejected_for_non_pawn_loan(): void
    {
        $memberId = DB::table('anggota')->insertGetId([
            'status' => 'aktif',
            'kode_anggota' => 'AGTJ0010',
            'kategori_shu' => 'anggota',
        ]);
        $productId = DB::table('jenis_pinjaman')->insertGetId([
            'status' => 'aktif',
            'maksimal_pinjaman' => 5000000,
            'tenor_max' => 6,
            'bunga' => 15,
            'frekuensi_diizinkan' => 'bulanan',
            'memerlukan_jaminan' => false,
        ]);
        DB::table('jenis_pinjaman_tarif')->insert(['jenis_pinjaman_id' => $productId, 'tenor_bulan' => 6, 'bunga' => 20]);
        $actor = $this->makeUser('teller');
        $actor->id = 1;

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Foto KK wajib diunggah untuk anggota AGTJ.');
        app(SubmitLoanApplicationAction::class)->handle([
            'anggota_id' => $memberId,
            'jenis_pinjaman_id' => $productId,
            'jumlah_pinjaman' => 1000000,
            'tenor' => 6,
            'frekuensi' => 'bulanan',
            'hari_angsuran' => 3,
            'tujuan_pinjaman' => 'Modal tambahan',
        ], $actor);
    }
}
