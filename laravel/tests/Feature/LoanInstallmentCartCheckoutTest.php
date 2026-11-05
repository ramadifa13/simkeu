<?php

namespace Tests\Feature;

use App\Actions\Loans\CheckoutInstallmentCartAction;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LoanInstallmentCartCheckoutTest extends TestCase
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
            $t->decimal('denda', 15, 2)->default(0);
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

    public function test_cart_checkout_pays_each_item_with_own_date_and_method(): void
    {
        DB::table('pinjaman')->insert([
            'id' => 1,
            'kode_pinjaman' => 'PJN-CART',
            'sisa_pokok' => 200000,
            'status' => 'aktif',
        ]);
        DB::table('angsuran')->insert([
            [
                'id' => 1,
                'pinjaman_id' => 1,
                'angsuran_ke' => 1,
                'jumlah_angsuran' => 110000,
                'pokok' => 100000,
                'denda' => 0,
                'tanggal_jatuh_tempo' => '2026-10-01',
                'status' => 'belum_bayar',
            ],
            [
                'id' => 2,
                'pinjaman_id' => 1,
                'angsuran_ke' => 2,
                'jumlah_angsuran' => 110000,
                'pokok' => 100000,
                'denda' => 0,
                'tanggal_jatuh_tempo' => '2026-11-01',
                'status' => 'belum_bayar',
            ],
        ]);

        $actor = $this->makeUser('admin');
        $actor->id = 9;

        $results = app(CheckoutInstallmentCartAction::class)->handle([
            ['installment_id' => 1, 'tanggal_bayar' => '2026-10-05', 'metode' => 'cash'],
            ['installment_id' => 2, 'tanggal_bayar' => '2026-11-03', 'metode' => 'transfer', 'denda' => 0],
        ], $actor);

        $this->assertCount(2, $results);
        $this->assertDatabaseHas('angsuran', ['id' => 1, 'status' => 'lunas', 'tanggal_bayar' => '2026-10-05']);
        $this->assertDatabaseHas('angsuran', ['id' => 2, 'status' => 'lunas', 'tanggal_bayar' => '2026-11-03']);
        $this->assertDatabaseHas('pinjaman', ['id' => 1, 'status' => 'lunas', 'sisa_pokok' => 0]);
    }
}
