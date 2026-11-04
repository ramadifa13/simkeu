<?php

namespace Tests\Feature;

use App\Actions\Loans\PayInstallmentAction;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LoanInstallmentPaymentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('pinjaman', function (Blueprint $t): void {
            $t->increments('id');
            $t->string('kode_pinjaman');
            $t->decimal('sisa_pokok', 15, 2);
            $t->string('status');
            $t->date('tanggal_lunas')->nullable();
        });
        Schema::create('angsuran', function (Blueprint $t): void {
            $t->increments('id');
            $t->unsignedInteger('pinjaman_id');
            $t->integer('angsuran_ke');
            $t->decimal('jumlah_angsuran', 15, 2);
            $t->decimal('pokok', 15, 2);
            $t->decimal('denda', 15, 2);
            $t->date('tanggal_jatuh_tempo');
            $t->date('tanggal_bayar')->nullable();
            $t->string('status');
            $t->string('cara_bayar')->nullable();
            $t->integer('keterlambatan_hari')->nullable();
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

    public function test_payment_locks_installment_updates_cash_and_marks_loan_paid(): void
    {
        DB::table('pinjaman')->insert(['id' => 1, 'kode_pinjaman' => 'PJN-UJI', 'sisa_pokok' => 100000, 'status' => 'aktif']);
        DB::table('angsuran')->insert(['id' => 1, 'pinjaman_id' => 1, 'angsuran_ke' => 1, 'jumlah_angsuran' => 110000, 'pokok' => 100000, 'denda' => 5000, 'tanggal_jatuh_tempo' => '2026-11-01', 'status' => 'belum_bayar']);
        $actor = new User(['role' => 'teller']);
        $actor->id = 1;
        $result = app(PayInstallmentAction::class)->handle(1, '2026-11-03', 'cash', $actor);
        $this->assertTrue($result['lunas']);
        $this->assertSame(115000.0, $result['total']);
        $this->assertDatabaseHas('pinjaman', ['id' => 1, 'status' => 'lunas', 'sisa_pokok' => 0]);
        $this->assertDatabaseHas('kas', ['jenis' => 'masuk', 'jumlah' => 115000, 'saldo_sesudah' => 115000]);
    }
}
