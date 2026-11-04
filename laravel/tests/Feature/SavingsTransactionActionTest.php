<?php

namespace Tests\Feature;

use App\Actions\Savings\CreateSavingsTransactionAction;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SavingsTransactionActionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('anggota', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('kode_anggota');
            $table->string('status');
        });
        Schema::create('jenis_simpanan', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('kode_jenis');
            $table->string('nama_jenis');
            $table->string('status');
            $table->decimal('minimum', 15, 2)->default(0);
            $table->decimal('maksimum', 15, 2)->default(0);
        });
        Schema::create('simpanan', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('anggota_id');
            $table->unsignedInteger('jenis_simpanan_id');
            $table->decimal('saldo', 15, 2);
        });
        Schema::create('transaksi', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('kode_transaksi');
            $table->unsignedInteger('anggota_id');
            $table->unsignedInteger('jenis_simpanan_id');
            $table->string('jenis_transaksi');
            $table->decimal('jumlah', 15, 2);
            $table->decimal('saldo_sebelum', 15, 2);
            $table->decimal('saldo_sesudah', 15, 2);
            $table->string('keterangan')->nullable();
            $table->unsignedInteger('user_id');
            $table->dateTime('tanggal_transaksi')->useCurrent();
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

    public function test_deposit_and_withdrawal_keep_savings_and_cash_in_sync(): void
    {
        $memberId = DB::table('anggota')->insertGetId(['kode_anggota' => 'AGT-TEST', 'status' => 'aktif']);
        $typeId = DB::table('jenis_simpanan')->insertGetId(['kode_jenis' => 'SUKARELA', 'nama_jenis' => 'Sukarela', 'status' => 'aktif', 'minimum' => 10000, 'maksimum' => 0]);
        $actor = new User(['role' => 'teller']);
        $actor->id = 1;
        $action = app(CreateSavingsTransactionAction::class);

        $deposit = $action->handle('setoran', ['anggota_id' => $memberId, 'jenis_simpanan_id' => $typeId, 'jumlah' => 100000], $actor);
        $withdrawal = $action->handle('penarikan', ['anggota_id' => $memberId, 'jenis_simpanan_id' => $typeId, 'jumlah' => 25000], $actor);

        $this->assertSame('100000.00', $deposit->jumlah);
        $this->assertSame('25000.00', $withdrawal->jumlah);
        $this->assertDatabaseHas('simpanan', ['anggota_id' => $memberId, 'jenis_simpanan_id' => $typeId, 'saldo' => 75000]);
        $this->assertDatabaseHas('kas', ['transaksi_id' => $deposit->id, 'jenis' => 'masuk', 'saldo_sebelum' => 0, 'saldo_sesudah' => 100000]);
        $this->assertDatabaseHas('kas', ['transaksi_id' => $withdrawal->id, 'jenis' => 'keluar', 'saldo_sebelum' => 100000, 'saldo_sesudah' => 75000]);
    }

    public function test_competing_withdrawal_cannot_overdraw_the_same_locked_account(): void
    {
        $memberId = DB::table('anggota')->insertGetId(['kode_anggota' => 'AGT-RACE', 'status' => 'aktif']);
        $typeId = DB::table('jenis_simpanan')->insertGetId(['kode_jenis' => 'SUKARELA', 'nama_jenis' => 'Sukarela', 'status' => 'aktif', 'minimum' => 0, 'maksimum' => 0]);
        DB::table('simpanan')->insert(['anggota_id' => $memberId, 'jenis_simpanan_id' => $typeId, 'saldo' => 100000]);
        DB::table('kas')->insert(['transaksi_id' => null, 'jenis' => 'masuk', 'jumlah' => 100000, 'saldo_sebelum' => 0, 'saldo_sesudah' => 100000, 'keterangan' => 'Saldo awal', 'tanggal' => '2026-11-04 08:00:00']);
        $actor = new User(['role' => 'teller']);
        $actor->id = 1;
        $action = app(CreateSavingsTransactionAction::class);

        $action->handle('penarikan', ['anggota_id' => $memberId, 'jenis_simpanan_id' => $typeId, 'jumlah' => 75000], $actor);

        try {
            $action->handle('penarikan', ['anggota_id' => $memberId, 'jenis_simpanan_id' => $typeId, 'jumlah' => 75000], $actor);
            $this->fail('Penarikan kedua seharusnya ditolak setelah saldo dikunci dan diperbarui.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('Saldo tidak mencukupi', $exception->getMessage());
        }

        $this->assertDatabaseHas('simpanan', ['anggota_id' => $memberId, 'jenis_simpanan_id' => $typeId, 'saldo' => 25000]);
        $this->assertSame(2, DB::table('kas')->count());
        $this->assertSame(25000.0, (float) DB::table('kas')->orderByDesc('id')->value('saldo_sesudah'));
    }
}
