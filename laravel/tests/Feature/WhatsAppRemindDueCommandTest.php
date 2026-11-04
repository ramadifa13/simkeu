<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use App\Jobs\SendWhatsAppDueReminderJob;
use Tests\TestCase;

class WhatsAppRemindDueCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('anggota', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('nama_lengkap');
            $table->string('telepon')->nullable();
        });
        Schema::create('pinjaman', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('anggota_id');
            $table->string('kode_pinjaman');
            $table->string('status');
        });
        Schema::create('angsuran', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('pinjaman_id');
            $table->unsignedInteger('angsuran_ke');
            $table->decimal('jumlah_angsuran', 15, 2);
            $table->string('status');
            $table->date('tanggal_jatuh_tempo');
        });
        DB::table('anggota')->insert(['id' => 1, 'nama_lengkap' => 'A', 'telepon' => '081234567890']);
        DB::table('pinjaman')->insert(['id' => 1, 'anggota_id' => 1, 'kode_pinjaman' => 'PJN-1', 'status' => 'aktif']);
        DB::table('angsuran')->insert([
            'id' => 11,
            'pinjaman_id' => 1,
            'angsuran_ke' => 1,
            'jumlah_angsuran' => 100000,
            'status' => 'belum_bayar',
            'tanggal_jatuh_tempo' => today()->addDay()->toDateString(),
        ]);
    }

    public function test_command_dispatches_jobs_for_tomorrow_due_installments(): void
    {
        Queue::fake();
        $this->artisan('wa:remind-due')->assertSuccessful();
        Queue::assertPushed(SendWhatsAppDueReminderJob::class, fn (SendWhatsAppDueReminderJob $job): bool => $job->installmentId === 11);
    }
}
