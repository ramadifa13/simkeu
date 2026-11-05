<?php

namespace Tests\Feature;

use App\Actions\Loans\MarkInstallmentReversalSeenAction;
use App\Actions\Loans\RequestInstallmentReversalAction;
use App\Actions\Loans\ReviewInstallmentReversalRequestAction;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class InstallmentReversalRequestFlowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('pinjaman', function (Blueprint $t): void {
            $t->increments('id');
            $t->string('kode_pinjaman');
            $t->string('status');
            $t->decimal('sisa_pokok', 15, 2);
            $t->date('tanggal_lunas')->nullable();
        });
        Schema::create('angsuran', function (Blueprint $t): void {
            $t->increments('id');
            $t->unsignedInteger('pinjaman_id');
            $t->unsignedInteger('angsuran_ke');
            $t->decimal('pokok', 15, 2);
            $t->decimal('denda', 15, 2)->default(0);
            $t->string('status');
            $t->date('tanggal_bayar')->nullable();
            $t->string('cara_bayar');
            $t->integer('keterlambatan_hari')->default(0);
        });
        Schema::create('kas', function (Blueprint $t): void {
            $t->increments('id');
            $t->unsignedInteger('transaksi_id');
            $t->string('jenis');
            $t->decimal('jumlah', 15, 2);
            $t->decimal('saldo_sebelum', 15, 2);
            $t->decimal('saldo_sesudah', 15, 2);
            $t->string('keterangan');
            $t->dateTime('tanggal');
        });
        Schema::create('pengajuan_pembatalan_angsuran', function (Blueprint $t): void {
            $t->increments('id');
            $t->unsignedInteger('angsuran_id');
            $t->unsignedInteger('teller_id');
            $t->unsignedInteger('admin_id')->nullable();
            $t->string('alasan');
            $t->timestamp('tanggal_pengajuan')->nullable();
            $t->timestamp('tanggal_keputusan')->nullable();
            $t->string('status');
            $t->integer('aktif')->nullable();
            $t->boolean('dilihat_teller')->default(0);
            $t->string('catatan_admin')->nullable();
        });
    }

    public function test_teller_request_admin_approval_marks_unread_until_teller_opens_loan(): void
    {
        DB::table('pinjaman')->insert(['id' => 1, 'kode_pinjaman' => 'PJN-REV', 'status' => 'aktif', 'sisa_pokok' => 0]);
        DB::table('angsuran')->insert([
            'id' => 1,
            'pinjaman_id' => 1,
            'angsuran_ke' => 1,
            'pokok' => 100000,
            'denda' => 0,
            'status' => 'lunas',
            'tanggal_bayar' => today()->toDateString(),
            'cara_bayar' => 'cicilan',
            'keterlambatan_hari' => 0,
        ]);
        DB::table('kas')->insert([
            'transaksi_id' => 1,
            'jenis' => 'masuk',
            'jumlah' => 110000,
            'saldo_sebelum' => 0,
            'saldo_sesudah' => 110000,
            'keterangan' => 'Pembayaran',
            'tanggal' => now(),
        ]);

        $teller = $this->makeUser('teller');
        $teller->id = 5;
        $admin = $this->makeUser('admin');
        $admin->id = 1;

        app(RequestInstallmentReversalAction::class)->handle(1, 1, $teller, 'Salah nominal input');
        $requestId = (int) DB::table('pengajuan_pembatalan_angsuran')->value('id');

        app(ReviewInstallmentReversalRequestAction::class)->handle(1, $requestId, 'disetujui', $admin, 'OK');
        $this->assertDatabaseHas('pengajuan_pembatalan_angsuran', ['id' => $requestId, 'status' => 'disetujui', 'dilihat_teller' => 0]);
        $this->assertDatabaseHas('angsuran', ['id' => 1, 'status' => 'belum_bayar']);

        $marked = app(MarkInstallmentReversalSeenAction::class)->handle($teller, 1);
        $this->assertSame(1, $marked);
        $this->assertDatabaseHas('pengajuan_pembatalan_angsuran', ['id' => $requestId, 'dilihat_teller' => 1]);
    }
}
