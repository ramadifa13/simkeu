<?php

namespace Tests\Feature;

use App\Actions\Loans\ReverseInstallmentPaymentAction;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ReverseInstallmentPaymentActionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('pinjaman', function (Blueprint $t): void {
            $t->increments('id');
            $t->string('kode_pinjaman');
            $t->string('status');
            $t->decimal('sisa_pokok', 15, 2);
            $t->date('tanggal_lunas')->nullable();
        });
        Schema::create('angsuran', function (Blueprint $t): void {
            $t->increments('id');
            $t->unsignedInteger('pinjaman_id');
            $t->unsignedInteger('angsuran_ke');
            $t->decimal('pokok', 15, 2);
            $t->decimal('denda', 15, 2);
            $t->string('status');
            $t->date('tanggal_bayar')->nullable();
            $t->string('cara_bayar');
            $t->integer('keterlambatan_hari');
        });
        Schema::create('kas', function (Blueprint $t): void {
            $t->increments('id');
            $t->unsignedInteger('transaksi_id');
            $t->string('jenis');
            $t->decimal('jumlah', 15, 2);
            $t->decimal('saldo_sebelum', 15, 2);
            $t->decimal('saldo_sesudah', 15, 2);
            $t->string('keterangan');
            $t->dateTime('tanggal');
        });
    }

    public function test_reversal_preserves_original_cash_and_creates_compensating_entry(): void
    {
        DB::table('pinjaman')->insert(['id' => 1, 'kode_pinjaman' => 'PJN-UJI', 'status' => 'lunas', 'sisa_pokok' => 0, 'tanggal_lunas' => '2026-11-04']);
        DB::table('angsuran')->insert(['id' => 1, 'pinjaman_id' => 1, 'angsuran_ke' => 1, 'pokok' => 100000, 'denda' => 0, 'status' => 'lunas', 'tanggal_bayar' => '2026-11-04', 'cara_bayar' => 'cicilan', 'keterlambatan_hari' => 0]);
        DB::table('kas')->insert(['transaksi_id' => 1, 'jenis' => 'masuk', 'jumlah' => 110000, 'saldo_sebelum' => 0, 'saldo_sesudah' => 110000, 'keterangan' => 'Pembayaran', 'tanggal' => '2026-11-04 10:00:00']);
        $admin = $this->makeUser('admin');
        $admin->id = 1;
        app(ReverseInstallmentPaymentAction::class)->handle(1, $admin, 'Input nominal keliru');
        $this->assertSame(2, DB::table('kas')->count());
        $this->assertDatabaseHas('kas', ['jenis' => 'keluar', 'jumlah' => 110000]);
        $this->assertDatabaseHas('angsuran', ['id' => 1, 'status' => 'belum_bayar']);
        $this->assertDatabaseHas('pinjaman', ['id' => 1, 'status' => 'aktif', 'sisa_pokok' => 100000]);
    }
}
