<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CutoverUatGateCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('pinjaman', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('status');
            $table->decimal('jumlah_pinjaman', 15, 2)->default(0);
            $table->decimal('sisa_pokok', 15, 2)->default(0);
        });
        Schema::create('angsuran', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('pinjaman_id');
            $table->string('status');
            $table->decimal('denda', 15, 2)->default(0);
        });
        Schema::create('simpanan', function (Blueprint $table): void {
            $table->increments('id');
            $table->decimal('saldo', 15, 2)->default(0);
        });
        Schema::create('kas', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('jenis');
            $table->decimal('jumlah', 15, 2)->default(0);
            $table->decimal('saldo_sesudah', 15, 2)->default(0);
        });
        Schema::create('waserda_transaksi', function (Blueprint $table): void {
            $table->increments('id');
            $table->decimal('total_bayar', 15, 2)->default(0);
        });

        DB::table('pinjaman')->insert(['id' => 1, 'status' => 'aktif', 'jumlah_pinjaman' => 1000000, 'sisa_pokok' => 500000]);
        DB::table('angsuran')->insert(['pinjaman_id' => 1, 'status' => 'belum_bayar', 'denda' => 0]);
    }

    public function test_uat_gate_passes_local_integrity_when_native_is_unavailable(): void
    {
        config(['parallel-run.native_reference_database' => '']);

        $exit = Artisan::call('simkeu:uat-gate');

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('GATE LULUS', Artisan::output());
    }

    public function test_uat_gate_fails_when_paid_loan_has_open_installments(): void
    {
        config(['parallel-run.native_reference_database' => '']);
        DB::table('pinjaman')->where('id', 1)->update(['status' => 'lunas', 'sisa_pokok' => 0]);

        $exit = Artisan::call('simkeu:uat-gate');

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('GATE GAGAL', Artisan::output());
    }
}
