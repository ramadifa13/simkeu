<?php

namespace Tests\Feature;

use App\Actions\Loans\CompleteDisbursementPhotosAction;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CompleteDisbursementPhotosActionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('jenis_pinjaman', function (Blueprint $t): void {
            $t->increments('id');
            $t->boolean('memerlukan_jaminan')->default(false);
        });
        Schema::create('pinjaman', function (Blueprint $t): void {
            $t->increments('id');
            $t->unsignedInteger('jenis_pinjaman_id');
            $t->unsignedInteger('pengajuan_id')->nullable();
            $t->string('foto_pencairan')->nullable();
        });
        Schema::create('jaminan_pinjaman', function (Blueprint $t): void {
            $t->increments('id');
            $t->unsignedInteger('pengajuan_id')->nullable();
            $t->unsignedInteger('pinjaman_id')->nullable();
            $t->string('jenis_barang')->nullable();
            $t->string('identitas_barang')->nullable();
            $t->text('deskripsi_barang')->nullable();
            $t->decimal('nilai_taksiran', 15, 2)->default(0);
            $t->string('foto_barang')->nullable();
            $t->string('foto_nasabah_barang')->nullable();
            $t->string('status_jaminan')->nullable();
        });
    }

    public function test_completing_photos_updates_loan_and_optional_collateral_images(): void
    {
        DB::table('jenis_pinjaman')->insert(['id' => 1, 'memerlukan_jaminan' => 1]);
        DB::table('pinjaman')->insert(['id' => 1, 'jenis_pinjaman_id' => 1, 'pengajuan_id' => 9, 'foto_pencairan' => null]);
        DB::table('jaminan_pinjaman')->insert([
            'id' => 1,
            'pengajuan_id' => 9,
            'pinjaman_id' => 1,
            'jenis_barang' => 'HP',
            'status_jaminan' => 'dititipkan',
        ]);

        $actor = new User(['role' => 'teller']);
        $actor->id = 3;

        $path = app(CompleteDisbursementPhotosAction::class)->handle(1, $actor, [
            'foto_nasabah' => 'pencairan/nasabah.jpg',
            'foto_barang' => 'jaminan/barang.jpg',
            'foto_nasabah_barang' => 'jaminan/bersama.jpg',
        ]);

        $this->assertSame('pencairan/nasabah.jpg', $path);
        $this->assertDatabaseHas('pinjaman', ['id' => 1, 'foto_pencairan' => 'pencairan/nasabah.jpg']);
        $this->assertDatabaseHas('jaminan_pinjaman', [
            'pinjaman_id' => 1,
            'foto_barang' => 'jaminan/barang.jpg',
            'foto_nasabah_barang' => 'jaminan/bersama.jpg',
        ]);
    }
}
