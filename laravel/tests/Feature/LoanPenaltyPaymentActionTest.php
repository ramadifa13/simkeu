<?php

namespace Tests\Feature;

use App\Actions\Loans\PayLoanPenaltyAction;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LoanPenaltyPaymentActionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('pinjaman', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('kode_pinjaman');
            $table->string('status');
        });
        Schema::create('angsuran', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('pinjaman_id');
            $table->unsignedInteger('angsuran_ke');
            $table->decimal('denda', 15, 2);
            $table->string('status');
            $table->unsignedInteger('keterlambatan_hari')->default(0);
        });
        Schema::create('kas', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('transaksi_id')->nullable();
            $table->string('jenis');
            $table->decimal('jumlah', 15, 2);
            $table->decimal('saldo_sebelum', 15, 2);
            $table->decimal('saldo_sesudah', 15, 2);
            $table->string('keterangan');
            $table->dateTime('tanggal');
        });
        Schema::create('layanan_bayar_denda', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('angsuran_id');
            $table->unsignedInteger('pinjaman_id');
            $table->unsignedInteger('user_id');
            $table->decimal('jumlah', 15, 2);
            $table->string('metode');
            $table->string('bukti');
            $table->unsignedInteger('transaksi_id')->nullable();
            $table->unsignedInteger('approved_by')->nullable();
            $table->dateTime('created_at');
            $table->unsignedInteger('hari_telat');
            $table->decimal('tarif', 15, 2);
            $table->decimal('denda_awal', 15, 2);
            $table->decimal('denda_akhir', 15, 2);
        });
    }

    public function test_penalty_payment_is_limited_to_the_remaining_penalty_and_creates_cash_entry(): void
    {
        DB::table('pinjaman')->insert(['id' => 1, 'kode_pinjaman' => 'PJN-UJI', 'status' => 'aktif']);
        DB::table('angsuran')->insert(['id' => 1, 'pinjaman_id' => 1, 'angsuran_ke' => 1, 'denda' => 10000, 'status' => 'telat', 'keterlambatan_hari' => 2]);
        $actor = new User(['role' => 'teller']);
        $actor->id = 9;

        $result = app(PayLoanPenaltyAction::class)->handle(1, 6000, 'cash', '2026-11-04', $actor);

        $this->assertSame(4000.0, $result['remaining']);
        $this->assertDatabaseHas('layanan_bayar_denda', ['angsuran_id' => 1, 'jumlah' => 6000]);
        $this->assertDatabaseHas('kas', ['jenis' => 'masuk', 'jumlah' => 6000, 'saldo_sesudah' => 6000]);
        $this->assertNotNull(DB::table('layanan_bayar_denda')->value('transaksi_id'));
    }
}
