<?php

namespace Tests\Feature;

use App\Actions\Loans\CloseInstallmentPeriodAction;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CloseInstallmentPeriodActionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('angsuran', function (Blueprint $t): void {
            $t->increments('id');
            $t->decimal('jumlah_angsuran', 15, 2);
            $t->decimal('denda', 15, 2);
            $t->string('status');
            $t->string('cara_bayar');
            $t->date('tanggal_bayar')->nullable();
            $t->date('tanggal_jatuh_tempo');
        });
        Schema::create('closing_angsuran_bulanan', function (Blueprint $t): void {
            $t->increments('id');
            $t->string('bulan')->unique();
            $t->string('status');
            $t->dateTime('ditutup_at')->nullable();
            $t->unsignedInteger('ditutup_oleh')->nullable();
            $t->dateTime('dibuka_at')->nullable();
            $t->unsignedInteger('dibuka_oleh')->nullable();
            $t->text('catatan')->nullable();
            $t->decimal('total_pembayaran', 15, 2);
            $t->unsignedInteger('jumlah_pembayaran');
        });
        Schema::create('closing_angsuran_detail', function (Blueprint $t): void {
            $t->increments('id');
            $t->unsignedInteger('closing_id');
            $t->unsignedInteger('angsuran_id');
            $t->decimal('nominal', 15, 2);
            $t->date('tanggal_bayar');
        });
    }

    public function test_close_snapshots_only_real_installments_in_the_same_month_and_reopen_removes_snapshot(): void
    {
        DB::table('angsuran')->insert([
            ['id' => 1, 'jumlah_angsuran' => 100000, 'denda' => 5000, 'status' => 'lunas', 'cara_bayar' => 'cicilan', 'tanggal_bayar' => '2026-11-04', 'tanggal_jatuh_tempo' => '2026-11-03'],
            ['id' => 2, 'jumlah_angsuran' => 100000, 'denda' => 0, 'status' => 'lunas', 'cara_bayar' => 'pelunasan', 'tanggal_bayar' => '2026-11-04', 'tanggal_jatuh_tempo' => '2026-11-04'],
            ['id' => 3, 'jumlah_angsuran' => 100000, 'denda' => 0, 'status' => 'lunas', 'cara_bayar' => 'cicilan', 'tanggal_bayar' => '2026-11-04', 'tanggal_jatuh_tempo' => '2026-10-30'],
        ]);
        $admin = $this->makeUser('admin');
        $admin->id = 1;
        $action = app(CloseInstallmentPeriodAction::class);
        $result = $action->close('2026-11', $admin, 'Uji closing');
        $this->assertSame(1, $result['jumlah']);
        $this->assertSame(105000.0, $result['total']);
        $this->assertSame(1, DB::table('closing_angsuran_detail')->count());
        $action->reopen('2026-11', $admin, 'Koreksi data');
        $this->assertDatabaseHas('closing_angsuran_bulanan', ['bulan' => '2026-11', 'status' => 'dibuka']);
        $this->assertSame(0, DB::table('closing_angsuran_detail')->count());
    }
}
