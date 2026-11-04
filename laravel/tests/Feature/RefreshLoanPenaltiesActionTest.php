<?php

namespace Tests\Feature;

use App\Actions\Loans\RefreshLoanPenaltiesAction;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RefreshLoanPenaltiesActionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('jenis_pinjaman', function (Blueprint $table): void {
            $table->increments('id');
            $table->boolean('memerlukan_jaminan');
        });
        Schema::create('pinjaman', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('jenis_pinjaman_id');
            $table->decimal('jumlah_pinjaman', 15, 2);
        });
        Schema::create('angsuran', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('pinjaman_id');
            $table->unsignedInteger('angsuran_ke');
            $table->decimal('denda', 15, 2)->default(0);
            $table->boolean('denda_manual')->default(false);
            $table->date('tanggal_jatuh_tempo');
            $table->string('status');
            $table->unsignedInteger('keterlambatan_hari')->default(0);
        });
        Schema::create('denda_tarif', function (Blueprint $table): void {
            $table->increments('id');
            $table->decimal('jumlah_pinjaman', 15, 2);
            $table->decimal('denda_per_hari', 15, 2);
        });
        Schema::create('layanan_pengaturan', function (Blueprint $table): void {
            $table->increments('id');
            $table->decimal('denda_jaminan_per_juta', 15, 2);
        });
    }

    public function test_refresh_marks_overdue_installment_and_caps_penalty_at_next_due_date(): void
    {
        DB::table('jenis_pinjaman')->insert(['id' => 1, 'memerlukan_jaminan' => false]);
        DB::table('pinjaman')->insert(['id' => 1, 'jenis_pinjaman_id' => 1, 'jumlah_pinjaman' => 2_000_000]);
        DB::table('denda_tarif')->insert(['jumlah_pinjaman' => 2_000_000, 'denda_per_hari' => 2500]);
        DB::table('layanan_pengaturan')->insert(['id' => 1, 'denda_jaminan_per_juta' => 5000]);
        DB::table('angsuran')->insert([
            ['id' => 1, 'pinjaman_id' => 1, 'angsuran_ke' => 1, 'tanggal_jatuh_tempo' => '2026-11-01', 'status' => 'belum_bayar'],
            ['id' => 2, 'pinjaman_id' => 1, 'angsuran_ke' => 2, 'tanggal_jatuh_tempo' => '2026-11-08', 'status' => 'belum_bayar'],
        ]);

        $updated = app(RefreshLoanPenaltiesAction::class)->handle(1, '2026-11-12');

        $this->assertSame(2, $updated);
        $this->assertDatabaseHas('angsuran', ['id' => 1, 'status' => 'telat', 'keterlambatan_hari' => 11, 'denda' => 17500]);
        $this->assertDatabaseHas('angsuran', ['id' => 2, 'status' => 'telat', 'keterlambatan_hari' => 4, 'denda' => 10000]);
    }
}
