<?php

namespace Tests\Feature;

use App\Actions\Waserda\CreateWaserdaTransactionAction;
use App\Actions\Waserda\DeleteWaserdaTransactionAction;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WaserdaTransactionActionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('anggota', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('kode_anggota');
            $table->string('status');
        });
        Schema::create('waserda_transaksi', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('kode_transaksi');
            $table->unsignedInteger('anggota_id');
            $table->text('items');
            $table->decimal('total_bayar', 15, 2);
            $table->string('metode_bayar');
            $table->unsignedInteger('user_id');
            $table->dateTime('created_at')->useCurrent();
        });
        Schema::create('kas', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('transaksi_id')->nullable();
            $table->string('jenis');
            $table->decimal('jumlah', 15, 2);
            $table->decimal('saldo_sebelum', 15, 2);
            $table->decimal('saldo_sesudah', 15, 2);
            $table->string('keterangan')->nullable();
            $table->dateTime('tanggal')->useCurrent();
        });
    }

    public function test_waserda_entry_and_admin_deletion_keep_cash_ledger_consistent(): void
    {
        $memberId = DB::table('anggota')->insertGetId(['kode_anggota' => 'AGT-TEST', 'status' => 'aktif']);
        $actor = $this->makeUser('admin');
        $actor->id = 1;

        $transaction = app(CreateWaserdaTransactionAction::class)->handle([
            'anggota_id' => $memberId,
            'metode_bayar' => 'tunai',
            'items' => [
                ['nama' => 'Beras', 'harga' => 12000, 'jumlah' => 2],
                ['nama' => 'Gula', 'harga' => 8000, 'jumlah' => 1],
            ],
        ], $actor);

        $this->assertSame('32000.00', $transaction->total_bayar);
        $this->assertDatabaseHas('kas', ['transaksi_id' => $transaction->id, 'jenis' => 'masuk', 'jumlah' => 32000, 'saldo_sesudah' => 32000]);

        app(DeleteWaserdaTransactionAction::class)->handle($transaction);

        $this->assertDatabaseMissing('waserda_transaksi', ['id' => $transaction->id]);
        $this->assertSame(0, DB::table('kas')->count());
    }
}
