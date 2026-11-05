<?php

namespace Tests\Feature;

use App\Actions\Loans\SettleLoanAction;
use App\Models\User;
use DomainException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SettleLoanActionTest extends TestCase
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
            $t->decimal('jumlah_angsuran', 15, 2);
            $t->decimal('bunga', 15, 2);
            $t->decimal('denda', 15, 2)->default(0);
            $t->string('status');
            $t->date('tanggal_bayar')->nullable();
            $t->string('cara_bayar')->default('');
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
        Schema::create('layanan_bayar_denda', function (Blueprint $t): void {
            $t->increments('id');
            $t->unsignedInteger('angsuran_id');
            $t->decimal('jumlah', 15, 2);
        });
    }

    public function test_settlement_marks_remaining_installments_paid_and_applies_admin_discount_to_interest_only(): void
    {
        DB::table('pinjaman')->insert(['id' => 1, 'kode_pinjaman' => 'PJN-UJI', 'status' => 'aktif', 'sisa_pokok' => 180000]);
        DB::table('angsuran')->insert([
            ['id' => 1, 'pinjaman_id' => 1, 'angsuran_ke' => 2, 'jumlah_angsuran' => 110000, 'bunga' => 10000, 'denda' => 0, 'status' => 'belum_bayar'],
            ['id' => 2, 'pinjaman_id' => 1, 'angsuran_ke' => 3, 'jumlah_angsuran' => 110000, 'bunga' => 10000, 'denda' => 0, 'status' => 'belum_bayar'],
        ]);
        $actor = $this->makeUser('admin');
        $actor->id = 1;
        $result = app(SettleLoanAction::class)->handle(1, '2026-11-04', 'cash', $actor, 50, 'Uji');

        $this->assertSame(210000.0, $result['total']);
        $this->assertSame(10000.0, $result['discount']);
        $this->assertDatabaseHas('pinjaman', ['id' => 1, 'status' => 'lunas', 'sisa_pokok' => 0]);
        $this->assertSame(2, DB::table('angsuran')->where('status', 'lunas')->count());
        $this->assertDatabaseHas('kas', ['jenis' => 'masuk', 'jumlah' => 210000]);
    }

    public function test_settlement_is_rejected_when_any_penalty_is_still_outstanding(): void
    {
        DB::table('pinjaman')->insert(['id' => 1, 'kode_pinjaman' => 'PJN-UJI', 'status' => 'aktif', 'sisa_pokok' => 100000]);
        DB::table('angsuran')->insert(['id' => 1, 'pinjaman_id' => 1, 'angsuran_ke' => 2, 'jumlah_angsuran' => 110000, 'bunga' => 10000, 'denda' => 5000, 'status' => 'telat']);
        $actor = $this->makeUser('teller');
        $actor->id = 2;

        $this->expectException(DomainException::class);
        app(SettleLoanAction::class)->handle(1, '2026-11-04', 'cash', $actor);
    }
}
