<?php

namespace Tests\Feature;

use App\Actions\Loans\DisburseLoanAction;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LoanDisbursementActionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('pengajuan_pinjaman', function (Blueprint $t): void {
            $t->increments('id');
            $t->unsignedInteger('anggota_id');
            $t->unsignedInteger('jenis_pinjaman_id');
            $t->decimal('jumlah_pinjaman', 15, 2);
            $t->integer('tenor');
            $t->decimal('bunga', 6, 2);
            $t->string('skema_bunga');
            $t->string('frekuensi');
            $t->string('status');
            $t->dateTime('tanggal_pengajuan');
            $t->string('kategori_shu')->default('anggota');
            $t->unsignedInteger('ketua_kelompok_id')->nullable();
        });
        Schema::create('pinjaman', function (Blueprint $t): void {
            $t->increments('id');
            $t->unsignedInteger('pengajuan_id');
            $t->string('kode_pinjaman');
            $t->unsignedInteger('anggota_id');
            $t->unsignedInteger('jenis_pinjaman_id');
            $t->decimal('jumlah_pinjaman', 15, 2);
            $t->decimal('sisa_pokok', 15, 2);
            $t->integer('tenor');
            $t->decimal('bunga', 6, 2);
            $t->string('skema_bunga');
            $t->string('frekuensi');
            $t->integer('jumlah_cicilan');
            $t->decimal('total_pinjaman', 15, 2);
            $t->decimal('angsuran_per_bulan', 15, 2);
            $t->date('tanggal_pencairan');
            $t->date('jatuh_tempo');
            $t->unsignedInteger('dicairkan_oleh');
            $t->string('status');
            $t->string('kategori_shu');
            $t->unsignedInteger('ketua_kelompok_id')->nullable();
        });
        Schema::create('angsuran', function (Blueprint $t): void {
            $t->increments('id');
            $t->unsignedInteger('pinjaman_id');
            $t->integer('angsuran_ke');
            $t->decimal('jumlah_angsuran', 15, 2);
            $t->decimal('pokok', 15, 2);
            $t->decimal('bunga', 15, 2);
            $t->decimal('denda', 15, 2);
            $t->date('tanggal_jatuh_tempo');
            $t->date('tanggal_bayar')->nullable();
            $t->string('status');
            $t->string('cara_bayar');
            $t->integer('keterlambatan_hari');
        });
        Schema::create('jaminan_pinjaman', function (Blueprint $t): void {
            $t->increments('id');
            $t->unsignedInteger('pengajuan_id');
            $t->unsignedInteger('pinjaman_id')->nullable();
        });
        Schema::create('kas', function (Blueprint $t): void {
            $t->increments('id');
            $t->unsignedInteger('transaksi_id')->nullable();
            $t->string('jenis');
            $t->decimal('jumlah', 15, 2);
            $t->decimal('saldo_sebelum', 15, 2);
            $t->decimal('saldo_sesudah', 15, 2);
            $t->string('keterangan');
            $t->dateTime('tanggal');
        });
    }

    public function test_disbursement_creates_schedule_cash_and_changes_application_status(): void
    {
        DB::table('pengajuan_pinjaman')->insert(['id' => 1, 'anggota_id' => 1, 'jenis_pinjaman_id' => 1, 'jumlah_pinjaman' => 2000000, 'tenor' => 3, 'bunga' => 35, 'skema_bunga' => 'flat', 'frekuensi' => 'mingguan', 'status' => 'disetujui', 'tanggal_pengajuan' => '2026-11-01 09:00:00']);
        DB::table('jaminan_pinjaman')->insert(['pengajuan_id' => 1]);
        DB::table('kas')->insert(['transaksi_id' => null, 'jenis' => 'masuk', 'jumlah' => 3000000, 'saldo_sebelum' => 0, 'saldo_sesudah' => 3000000, 'keterangan' => 'Modal', 'tanggal' => '2026-11-01 08:00:00']);
        $actor = new User(['role' => 'admin']);
        $actor->id = 1;
        $result = app(DisburseLoanAction::class)->handle(1, '2026-11-02', $actor);
        $this->assertSame(12, $result['jumlah_cicilan']);
        $this->assertDatabaseHas('pengajuan_pinjaman', ['id' => 1, 'status' => 'dicairkan']);
        $this->assertSame(12, DB::table('angsuran')->count());
        $this->assertSame(1000000.0, (float) DB::table('kas')->orderByDesc('id')->value('saldo_sesudah'));
    }
}
