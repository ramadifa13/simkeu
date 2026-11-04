<?php

namespace Tests\Feature;

use App\Actions\Cash\RecalculateCashLedgerAction;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CashLedgerRecalculationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('kas', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('transaksi_id')->nullable();
            $table->string('jenis', 10);
            $table->decimal('jumlah', 15, 2);
            $table->decimal('saldo_sebelum', 15, 2)->default(0);
            $table->decimal('saldo_sesudah', 15, 2)->default(0);
            $table->string('keterangan')->nullable();
            $table->dateTime('tanggal');
        });
    }

    public function test_it_rebuilds_cash_balances_by_date_then_id(): void
    {
        DB::table('kas')->insert([
            ['jenis' => 'masuk', 'jumlah' => 50000, 'saldo_sebelum' => 99, 'saldo_sesudah' => 99, 'keterangan' => 'Setoran', 'tanggal' => '2026-11-03 09:00:00'],
            ['jenis' => 'keluar', 'jumlah' => 12500, 'saldo_sebelum' => 99, 'saldo_sesudah' => 99, 'keterangan' => 'Penarikan', 'tanggal' => '2026-11-03 10:00:00'],
            ['jenis' => 'masuk', 'jumlah' => 10000, 'saldo_sebelum' => 99, 'saldo_sesudah' => 99, 'keterangan' => 'Setoran awal', 'tanggal' => '2026-11-02 09:00:00'],
        ]);

        $result = app(RecalculateCashLedgerAction::class)->handle();
        $rows = DB::table('kas')->orderBy('tanggal')->orderBy('id')->get();

        $this->assertSame(3, $result['entries']);
        $this->assertSame(47500.0, $result['saldo_akhir']);
        $this->assertSame('0', (string) $rows[0]->saldo_sebelum);
        $this->assertSame('10000', (string) $rows[0]->saldo_sesudah);
        $this->assertSame('10000', (string) $rows[1]->saldo_sebelum);
        $this->assertSame('60000', (string) $rows[1]->saldo_sesudah);
        $this->assertSame('60000', (string) $rows[2]->saldo_sebelum);
        $this->assertSame('47500', (string) $rows[2]->saldo_sesudah);
    }
}
