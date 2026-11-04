<?php

namespace Tests\Feature;

use App\Actions\Loans\RequestCollateralReturnAction;
use App\Actions\Loans\ReturnCollateralAction;
use App\Actions\Loans\ReviewCollateralReturnAction;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CollateralReturnFlowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('pinjaman', function (Blueprint $t): void {
            $t->increments('id');
            $t->string('status');
        });
        Schema::create('jaminan_pinjaman', function (Blueprint $t): void {
            $t->increments('id');
            $t->unsignedInteger('pinjaman_id');
            $t->string('status_jaminan');
            $t->string('foto_barang')->nullable();
            $t->date('tanggal_dikembalikan')->nullable();
        });
        Schema::create('layanan_jaminan_kembali', function (Blueprint $t): void {
            $t->increments('id');
            $t->unsignedInteger('pinjaman_id');
            $t->unsignedInteger('user_id')->nullable();
            $t->unsignedInteger('admin_id')->nullable();
            $t->string('status');
            $t->string('alasan')->nullable();
            $t->string('bukti_jaminan')->nullable();
            $t->string('bukti_lunas')->nullable();
            $t->string('catatan_admin')->nullable();
            $t->timestamp('diperiksa_at')->nullable();
            $t->string('foto')->nullable();
            $t->string('ttd')->nullable();
            $t->string('ttd_teller')->nullable();
            $t->timestamp('diserahkan_at')->nullable();
        });
    }

    public function test_admin_can_mark_inspection_then_approve_and_hand_over_collateral(): void
    {
        DB::table('pinjaman')->insert(['id' => 1, 'status' => 'lunas']);
        DB::table('jaminan_pinjaman')->insert(['id' => 1, 'pinjaman_id' => 1, 'status_jaminan' => 'dititipkan', 'foto_barang' => 'jaminan/a.jpg']);

        $teller = new User(['role' => 'teller']);
        $teller->id = 2;
        $admin = new User(['role' => 'admin']);
        $admin->id = 1;

        app(RequestCollateralReturnAction::class)->handle(1, $teller, 'Nasabah mengambil barang', 'jaminan/b.jpg', 'lunas/c.jpg');
        $this->assertDatabaseHas('layanan_jaminan_kembali', ['pinjaman_id' => 1, 'status' => 'menunggu']);

        app(ReviewCollateralReturnAction::class)->handle(1, 'pengecekan', $admin, 'Sedang diperiksa Admin');
        $this->assertDatabaseHas('layanan_jaminan_kembali', ['pinjaman_id' => 1, 'status' => 'pengecekan']);

        app(ReviewCollateralReturnAction::class)->handle(1, 'disetujui', $admin, 'Bukti lengkap dan sesuai');
        $this->assertDatabaseHas('layanan_jaminan_kembali', ['pinjaman_id' => 1, 'status' => 'disetujui']);

        app(ReturnCollateralAction::class)->handle(1, $teller, 'serah/foto.jpg', 'ttd/n.webp', 'ttd/t.webp', 'Kondisi baik', true);
        $this->assertDatabaseHas('layanan_jaminan_kembali', ['pinjaman_id' => 1, 'status' => 'diserahkan']);
        $this->assertDatabaseHas('jaminan_pinjaman', ['pinjaman_id' => 1, 'status_jaminan' => 'dikembalikan']);
    }
}
