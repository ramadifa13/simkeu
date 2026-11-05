<?php

namespace Tests\Feature;

use App\Actions\Loans\PayInstallmentAction;
use App\Actions\Loans\ReverseInstallmentPaymentAction;
use App\Models\User;
use App\Support\CashEntry;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CashEntryRefTypeTest extends TestCase
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
            $t->string('ref_type', 40)->nullable();
            $t->string('jenis');
            $t->decimal('jumlah', 15, 2);
            $t->decimal('saldo_sebelum', 15, 2);
            $t->decimal('saldo_sesudah', 15, 2);
            $t->string('keterangan');
            $t->dateTime('tanggal');
        });
    }

    public function test_pay_and_reverse_tag_cash_ref_type(): void
    {
        DB::table('pinjaman')->insert(['id' => 1, 'kode_pinjaman' => 'PJN-REF', 'sisa_pokok' => 100000, 'status' => 'aktif']);
        DB::table('angsuran')->insert([
            'id' => 1,
            'pinjaman_id' => 1,
            'angsuran_ke' => 1,
            'jumlah_angsuran' => 110000,
            'pokok' => 100000,
            'denda' => 0,
            'tanggal_jatuh_tempo' => '2026-10-01',
            'status' => 'belum_bayar',
        ]);
        // Colliding id from another domain must not be reversed.
        DB::table('kas')->insert([
            'transaksi_id' => 1,
            'ref_type' => CashEntry::TRANSAKSI,
            'jenis' => 'masuk',
            'jumlah' => 999999,
            'saldo_sebelum' => 0,
            'saldo_sesudah' => 999999,
            'keterangan' => 'Setoran simpanan X',
            'tanggal' => now(),
        ]);

        $actor = $this->makeUser('admin');
        $actor->id = 3;

        app(PayInstallmentAction::class)->handle(1, '2026-10-05', 'cash', $actor);
        $this->assertDatabaseHas('kas', [
            'transaksi_id' => 1,
            'ref_type' => CashEntry::ANGSURAN,
            'jenis' => 'masuk',
            'jumlah' => 110000,
        ]);

        app(ReverseInstallmentPaymentAction::class)->handle(1, $actor, 'Koreksi nominal uji');
        $this->assertDatabaseHas('kas', [
            'transaksi_id' => 1,
            'ref_type' => CashEntry::ANGSURAN_REVERSAL,
            'jenis' => 'keluar',
            'jumlah' => 110000,
        ]);
        $this->assertDatabaseHas('kas', [
            'transaksi_id' => 1,
            'ref_type' => CashEntry::TRANSAKSI,
            'jumlah' => 999999,
        ]);
        $this->assertDatabaseHas('angsuran', ['id' => 1, 'status' => 'belum_bayar']);
    }
}
