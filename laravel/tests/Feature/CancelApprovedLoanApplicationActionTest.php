<?php

namespace Tests\Feature;

use App\Actions\Loans\CancelApprovedLoanApplicationAction;
use DomainException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CancelApprovedLoanApplicationActionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('pengajuan_pinjaman', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('status');
            $table->text('catatan_admin')->nullable();
        });
        Schema::create('pinjaman', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('pengajuan_id');
        });
    }

    public function test_only_an_approved_and_undisbursed_application_can_be_cancelled(): void
    {
        DB::table('pengajuan_pinjaman')->insert(['id' => 1, 'status' => 'disetujui']);
        app(CancelApprovedLoanApplicationAction::class)->handle(1, 'Dokumen belum lengkap.');

        $this->assertDatabaseHas('pengajuan_pinjaman', ['id' => 1, 'status' => 'batal_cair', 'catatan_admin' => 'Dokumen belum lengkap.']);
        $this->expectException(DomainException::class);
        app(CancelApprovedLoanApplicationAction::class)->handle(1, 'Tidak boleh dua kali.');
    }
}
