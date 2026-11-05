<?php

namespace Tests\Feature;

use App\Models\Anggota;
use App\Models\Angsuran;
use App\Models\JaminanPinjaman;
use App\Models\JenisPinjaman;
use App\Models\PengajuanPinjaman;
use App\Models\Pinjaman;
use App\Models\ShuPeriode;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LoanDomainModelsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('anggota', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('nama_lengkap')->nullable();
            $table->string('status')->default('aktif');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('jenis_pinjaman', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('kode_jenis')->nullable();
            $table->string('nama_jenis')->nullable();
            $table->decimal('maksimal_pinjaman', 15, 2)->default(0);
            $table->integer('tenor_max')->default(12);
            $table->decimal('bunga', 6, 2)->default(0);
            $table->string('status')->default('aktif');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('jenis_pinjaman_tarif', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('jenis_pinjaman_id');
            $table->integer('tenor_bulan');
            $table->decimal('bunga', 6, 2);
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('users', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('username')->nullable();
            $table->string('nama_lengkap')->nullable();
            $table->string('role')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('pengajuan_pinjaman', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('kode_pengajuan');
            $table->unsignedInteger('anggota_id');
            $table->unsignedInteger('jenis_pinjaman_id');
            $table->decimal('jumlah_pinjaman', 15, 2);
            $table->integer('tenor');
            $table->decimal('bunga', 6, 2);
            $table->string('skema_bunga')->default('flat');
            $table->string('frekuensi')->default('bulanan');
            $table->text('tujuan_pinjaman')->nullable();
            $table->string('status')->default('menunggu');
            $table->text('catatan_admin')->nullable();
            $table->unsignedInteger('admin_id')->nullable();
            $table->unsignedInteger('user_id')->nullable();
            $table->dateTime('tanggal_pengajuan')->nullable();
            $table->dateTime('tanggal_persetujuan')->nullable();
            $table->integer('hari_angsuran')->nullable();
            $table->boolean('fasilitas_prioritas')->default(false);
            $table->string('kategori_shu')->default('anggota');
            $table->unsignedInteger('ketua_kelompok_id')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('pinjaman', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('pengajuan_id');
            $table->string('kode_pinjaman');
            $table->unsignedInteger('anggota_id');
            $table->unsignedInteger('jenis_pinjaman_id');
            $table->decimal('jumlah_pinjaman', 15, 2);
            $table->decimal('sisa_pokok', 15, 2);
            $table->integer('tenor');
            $table->decimal('bunga', 6, 2);
            $table->string('skema_bunga')->default('flat');
            $table->string('frekuensi')->default('bulanan');
            $table->integer('jumlah_cicilan')->nullable();
            $table->decimal('total_pinjaman', 15, 2);
            $table->decimal('angsuran_per_bulan', 15, 2);
            $table->date('tanggal_pencairan');
            $table->date('jatuh_tempo');
            $table->string('foto_pencairan')->nullable();
            $table->unsignedInteger('dicairkan_oleh')->nullable();
            $table->date('tanggal_lunas')->nullable();
            $table->string('status')->default('aktif');
            $table->boolean('input_mundur')->default(false);
            $table->integer('cicilan_dimuka')->default(0);
            $table->string('kategori_shu')->default('anggota');
            $table->unsignedInteger('ketua_kelompok_id')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('angsuran', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('pinjaman_id');
            $table->integer('angsuran_ke');
            $table->decimal('jumlah_angsuran', 15, 2);
            $table->decimal('pokok', 15, 2);
            $table->decimal('bunga', 15, 2);
            $table->decimal('denda', 15, 2)->default(0);
            $table->boolean('denda_manual')->default(false);
            $table->date('tanggal_jatuh_tempo');
            $table->date('tanggal_bayar')->nullable();
            $table->string('status')->default('belum_bayar');
            $table->string('cara_bayar')->default('cicilan');
            $table->integer('keterlambatan_hari')->default(0);
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('jaminan_pinjaman', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('pengajuan_id');
            $table->unsignedInteger('pinjaman_id')->nullable();
            $table->string('jenis_barang');
            $table->string('identitas_barang');
            $table->text('deskripsi_barang');
            $table->decimal('nilai_taksiran', 15, 2);
            $table->string('foto_barang')->nullable();
            $table->string('foto_nasabah_barang')->nullable();
            $table->string('status_jaminan')->default('dititipkan');
            $table->date('tanggal_dikembalikan')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('shu_periode', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('tahun')->unique();
            $table->string('nama_periode');
            $table->date('tanggal_mulai');
            $table->date('tanggal_selesai');
            $table->string('status')->default('draft');
            $table->decimal('total_shu', 15, 0)->default(0);
            $table->text('kebijakan_json');
            $table->text('alokasi_json')->nullable();
            $table->string('snapshot_hash', 64)->nullable();
            $table->string('referensi_rat')->nullable();
            $table->unsignedInteger('dibuat_oleh');
            $table->dateTime('dihitung_at')->nullable();
            $table->unsignedInteger('disahkan_oleh')->nullable();
            $table->dateTime('disahkan_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function test_relation_methods_exist_and_basic_create_works(): void
    {
        $this->assertInstanceOf(HasMany::class, (new Anggota)->pengajuanPinjaman());
        $this->assertInstanceOf(HasMany::class, (new Anggota)->pinjaman());
        $this->assertInstanceOf(HasMany::class, (new JenisPinjaman)->tarif());
        $this->assertInstanceOf(HasMany::class, (new JenisPinjaman)->pinjaman());
        $this->assertInstanceOf(BelongsTo::class, (new PengajuanPinjaman)->anggota());
        $this->assertInstanceOf(BelongsTo::class, (new PengajuanPinjaman)->jenisPinjaman());
        $this->assertInstanceOf(BelongsTo::class, (new PengajuanPinjaman)->pengaju());
        $this->assertInstanceOf(BelongsTo::class, (new PengajuanPinjaman)->admin());
        $this->assertInstanceOf(HasOne::class, (new PengajuanPinjaman)->jaminanPinjaman());
        $this->assertInstanceOf(HasMany::class, (new PengajuanPinjaman)->jaminanList());
        $this->assertInstanceOf(HasOne::class, (new PengajuanPinjaman)->pinjaman());
        $this->assertInstanceOf(BelongsTo::class, (new Pinjaman)->pengajuanPinjaman());
        $this->assertInstanceOf(HasMany::class, (new Pinjaman)->angsuran());
        $this->assertInstanceOf(BelongsTo::class, (new Angsuran)->pinjaman());
        $this->assertInstanceOf(BelongsTo::class, (new JaminanPinjaman)->pengajuanPinjaman());
        $this->assertInstanceOf(BelongsTo::class, (new JaminanPinjaman)->pinjaman());
        $this->assertInstanceOf(BelongsTo::class, (new ShuPeriode)->pembuat());

        $member = Anggota::query()->create(['nama_lengkap' => 'Budi', 'status' => 'aktif']);
        $product = JenisPinjaman::query()->create([
            'kode_jenis' => 'PDG',
            'nama_jenis' => 'Gadai',
            'maksimal_pinjaman' => 5000000,
            'tenor_max' => 6,
            'bunga' => 15,
            'status' => 'aktif',
        ]);

        $application = PengajuanPinjaman::query()->create([
            'kode_pengajuan' => 'PP-1',
            'anggota_id' => $member->id,
            'jenis_pinjaman_id' => $product->id,
            'jumlah_pinjaman' => 2000000,
            'tenor' => 3,
            'bunga' => 15,
            'skema_bunga' => 'flat',
            'frekuensi' => 'mingguan',
            'status' => 'disetujui',
            'tanggal_pengajuan' => '2026-11-01 09:00:00',
            'kategori_shu' => 'anggota',
        ]);

        $loan = Pinjaman::query()->create([
            'pengajuan_id' => $application->id,
            'kode_pinjaman' => 'PJN-1',
            'anggota_id' => $member->id,
            'jenis_pinjaman_id' => $product->id,
            'jumlah_pinjaman' => 2000000,
            'sisa_pokok' => 2000000,
            'tenor' => 3,
            'bunga' => 15,
            'skema_bunga' => 'flat',
            'frekuensi' => 'mingguan',
            'jumlah_cicilan' => 12,
            'total_pinjaman' => 2300000,
            'angsuran_per_bulan' => 191666.67,
            'tanggal_pencairan' => '2026-11-02',
            'jatuh_tempo' => '2027-01-25',
            'status' => 'aktif',
            'kategori_shu' => 'anggota',
        ]);

        $installment = Angsuran::query()->create([
            'pinjaman_id' => $loan->id,
            'angsuran_ke' => 1,
            'jumlah_angsuran' => 191666.67,
            'pokok' => 166666.67,
            'bunga' => 25000,
            'denda' => 0,
            'tanggal_jatuh_tempo' => '2026-11-09',
            'status' => 'belum_bayar',
            'cara_bayar' => 'cicilan',
            'keterlambatan_hari' => 0,
        ]);

        $collateral = JaminanPinjaman::query()->create([
            'pengajuan_id' => $application->id,
            'pinjaman_id' => $loan->id,
            'jenis_barang' => 'HP',
            'identitas_barang' => 'IMEI-1',
            'deskripsi_barang' => 'Smartphone',
            'nilai_taksiran' => 2500000,
            'status_jaminan' => 'dititipkan',
        ]);

        $period = ShuPeriode::query()->create([
            'tahun' => 2026,
            'nama_periode' => 'SHU 2026',
            'tanggal_mulai' => '2026-01-01',
            'tanggal_selesai' => '2026-12-31',
            'status' => 'draft',
            'total_shu' => 0,
            'kebijakan_json' => '{}',
            'dibuat_oleh' => 1,
        ]);

        $this->assertTrue($application->exists);
        $this->assertTrue($loan->exists);
        $this->assertTrue($installment->exists);
        $this->assertTrue($collateral->exists);
        $this->assertTrue($period->exists);
        $this->assertSame('2000000.00', (string) $loan->jumlah_pinjaman);
        $this->assertTrue($member->pengajuanPinjaman()->whereKey($application->id)->exists());
        $this->assertTrue($member->pinjaman()->whereKey($loan->id)->exists());
        $this->assertTrue($loan->angsuran()->whereKey($installment->id)->exists());
        $this->assertSame($application->id, $loan->pengajuanPinjaman->id);
        $this->assertSame($loan->id, $application->fresh()->pinjaman->id);
        $this->assertSame($application->id, $collateral->pengajuanPinjaman->id);
    }
}
