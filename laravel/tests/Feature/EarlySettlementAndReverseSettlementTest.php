<?php

namespace Tests\Feature;

use App\Actions\Loans\RequestEarlySettlementAction;
use App\Actions\Loans\ReverseSettlementAction;
use App\Actions\Loans\ReviewEarlySettlementAction;
use App\Actions\Loans\SettleLoanAction;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EarlySettlementAndReverseSettlementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('pinjaman', function (Blueprint $t): void {
            $t->increments('id');
            $t->unsignedInteger('jenis_pinjaman_id')->nullable();
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
            $t->decimal('pokok', 15, 2)->default(0);
            $t->decimal('bunga', 15, 2);
            $t->decimal('denda', 15, 2)->default(0);
            $t->string('status');
            $t->date('tanggal_bayar')->nullable();
            $t->string('cara_bayar')->default('');
            $t->integer('keterlambatan_hari')->default(0);
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
        Schema::create('layanan_pelunasan', function (Blueprint $t): void {
            $t->increments('id');
            $t->unsignedInteger('pinjaman_id');
            $t->unsignedInteger('user_id')->nullable();
            $t->unsignedInteger('admin_id')->nullable();
            $t->string('status');
            $t->decimal('diskon_persen', 8, 2)->default(0);
            $t->string('catatan')->nullable();
            $t->timestamp('diputuskan_at')->nullable();
            $t->timestamp('created_at')->nullable();
        });
        Schema::create('layanan_pembayaran', function (Blueprint $t): void {
            $t->increments('id');
            $t->unsignedInteger('pinjaman_id')->nullable();
            $t->unsignedInteger('angsuran_id')->nullable();
            $t->unsignedInteger('user_id')->nullable();
            $t->string('jenis');
            $t->decimal('jumlah', 15, 2);
            $t->string('metode')->nullable();
            $t->string('bukti')->nullable();
            $t->decimal('bunga_diskon', 15, 2)->default(0);
            $t->timestamp('dibatalkan_at')->nullable();
        });
    }

    public function test_early_settlement_discount_then_admin_can_reverse_settlement(): void
    {
        DB::table('pinjaman')->insert(['id' => 1, 'jenis_pinjaman_id' => null, 'kode_pinjaman' => 'PJN-EARLY', 'status' => 'aktif', 'sisa_pokok' => 180000]);
        DB::table('angsuran')->insert([
            ['id' => 1, 'pinjaman_id' => 1, 'angsuran_ke' => 2, 'jumlah_angsuran' => 110000, 'pokok' => 100000, 'bunga' => 10000, 'denda' => 0, 'status' => 'belum_bayar'],
            ['id' => 2, 'pinjaman_id' => 1, 'angsuran_ke' => 3, 'jumlah_angsuran' => 110000, 'pokok' => 80000, 'bunga' => 10000, 'denda' => 0, 'status' => 'belum_bayar'],
        ]);

        $teller = $this->makeUser('teller');
        $teller->id = 2;
        $admin = $this->makeUser('admin');
        $admin->id = 1;

        app(RequestEarlySettlementAction::class)->handle(1, $teller, 'Minta diskon bunga');
        app(ReviewEarlySettlementAction::class)->handle(1, 'disetujui', 50, $admin, 'Disetujui uji');
        $this->assertDatabaseHas('layanan_pelunasan', ['pinjaman_id' => 1, 'status' => 'disetujui', 'diskon_persen' => 50]);

        $settled = app(SettleLoanAction::class)->handle(1, '2026-11-04', 'cash', $admin, 50, 'Uji early');
        $this->assertSame(210000.0, $settled['total']);
        $this->assertDatabaseHas('pinjaman', ['id' => 1, 'status' => 'lunas']);
        $this->assertDatabaseHas('layanan_pelunasan', ['pinjaman_id' => 1, 'status' => 'selesai']);

        $reversed = app(ReverseSettlementAction::class)->handle(1, $admin, 'Salah proses pelunasan');
        $this->assertGreaterThan(0, $reversed);
        $this->assertDatabaseHas('pinjaman', ['id' => 1, 'status' => 'aktif']);
        $this->assertSame(2, DB::table('angsuran')->where('status', 'belum_bayar')->count());
        $this->assertDatabaseHas('kas', ['jenis' => 'keluar']);
    }
}
