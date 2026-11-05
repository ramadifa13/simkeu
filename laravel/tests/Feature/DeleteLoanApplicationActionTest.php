<?php

namespace Tests\Feature;

use App\Actions\Loans\DeleteLoanApplicationAction;
use DomainException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DeleteLoanApplicationActionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('jenis_pinjaman', function (Blueprint $table): void {
            $table->increments('id');
            $table->boolean('memerlukan_jaminan')->default(false);
        });
        Schema::create('anggota', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('kode_anggota_jaminan')->nullable();
        });
        Schema::create('pengajuan_pinjaman', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('anggota_id');
            $table->unsignedInteger('jenis_pinjaman_id');
            $table->string('status');
        });
        Schema::create('pinjaman', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('pengajuan_id');
            $table->unsignedInteger('anggota_id')->nullable();
            $table->unsignedInteger('jenis_pinjaman_id')->nullable();
        });
        Schema::create('jaminan_pinjaman', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('pengajuan_id');
        });

        DB::table('jenis_pinjaman')->insert([
            ['id' => 1, 'memerlukan_jaminan' => 0],
            ['id' => 2, 'memerlukan_jaminan' => 1],
        ]);
        DB::table('anggota')->insert(['id' => 10, 'kode_anggota_jaminan' => 'AGTJ-10']);
    }

    public function test_admin_can_delete_waiting_or_rejected_application_without_loan(): void
    {
        DB::table('pengajuan_pinjaman')->insert([
            'id' => 1,
            'anggota_id' => 10,
            'jenis_pinjaman_id' => 1,
            'status' => 'menunggu',
        ]);
        DB::table('jaminan_pinjaman')->insert(['pengajuan_id' => 1]);

        app(DeleteLoanApplicationAction::class)->handle(1);

        $this->assertDatabaseMissing('pengajuan_pinjaman', ['id' => 1]);
        $this->assertDatabaseMissing('jaminan_pinjaman', ['pengajuan_id' => 1]);
    }

    public function test_approved_application_cannot_be_deleted(): void
    {
        DB::table('pengajuan_pinjaman')->insert([
            'id' => 2,
            'anggota_id' => 10,
            'jenis_pinjaman_id' => 1,
            'status' => 'disetujui',
        ]);

        $this->expectException(DomainException::class);
        app(DeleteLoanApplicationAction::class)->handle(2);
    }

    public function test_deleting_last_pawn_application_clears_member_pawn_code(): void
    {
        DB::table('pengajuan_pinjaman')->insert([
            'id' => 3,
            'anggota_id' => 10,
            'jenis_pinjaman_id' => 2,
            'status' => 'ditolak',
        ]);

        app(DeleteLoanApplicationAction::class)->handle(3);

        $this->assertDatabaseHas('anggota', ['id' => 10, 'kode_anggota_jaminan' => null]);
    }
}
