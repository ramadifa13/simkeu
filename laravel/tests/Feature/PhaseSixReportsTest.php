<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PhaseSixReportsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('username');
            $table->string('password')->nullable();
            $table->string('nama_lengkap');
            $table->string('role');
            $table->string('status')->default('aktif');
        });
        Schema::create('koperasi', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('nama');
            $table->string('logo')->nullable();
        });
        Schema::create('anggota', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('kode_anggota');
            $table->string('nama_lengkap');
            $table->string('telepon')->nullable();
            $table->string('email')->nullable();
            $table->string('status');
            $table->date('tanggal_daftar')->nullable();
        });
        Schema::create('jenis_simpanan', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('kode_jenis');
            $table->string('nama_jenis');
        });
        Schema::create('transaksi', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('kode_transaksi');
            $table->unsignedInteger('anggota_id');
            $table->unsignedInteger('jenis_simpanan_id');
            $table->string('jenis_transaksi');
            $table->decimal('jumlah', 15, 2);
            $table->dateTime('tanggal_transaksi');
        });
        Schema::create('kas', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('jenis');
            $table->decimal('jumlah', 15, 2);
            $table->decimal('saldo_sesudah', 15, 2);
            $table->string('keterangan')->nullable();
            $table->dateTime('tanggal');
        });
        Schema::create('waserda_transaksi', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('kode_transaksi');
            $table->unsignedInteger('anggota_id');
            $table->decimal('total_bayar', 15, 2);
            $table->string('metode_bayar');
            $table->dateTime('created_at');
        });
        Schema::create('jenis_pinjaman', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('nama_jenis');
        });
        Schema::create('pinjaman', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('kode_pinjaman');
            $table->unsignedInteger('anggota_id');
            $table->unsignedInteger('jenis_pinjaman_id')->nullable();
            $table->string('status')->default('aktif');
            $table->date('tanggal_lunas')->nullable();
        });
        Schema::create('angsuran', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('pinjaman_id');
            $table->unsignedInteger('angsuran_ke');
            $table->decimal('denda', 15, 2);
            $table->string('status');
            $table->date('tanggal_jatuh_tempo');
        });
        Schema::create('activity_logs', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('user_id')->nullable();
            $table->string('action');
            $table->string('auditable_type');
            $table->unsignedInteger('auditable_id')->nullable();
            $table->json('context')->nullable();
            $table->string('ip_address')->nullable();
            $table->string('user_agent')->nullable();
            $table->dateTime('created_at')->useCurrent();
        });

        DB::table('users')->insert([
            ['id' => 1, 'username' => 'admin', 'nama_lengkap' => 'Admin Uji', 'role' => 'admin', 'status' => 'aktif'],
            ['id' => 2, 'username' => 'teller', 'nama_lengkap' => 'Teller Uji', 'role' => 'teller', 'status' => 'aktif'],
        ]);
        DB::table('koperasi')->insert(['nama' => 'Koperasi Uji']);
        DB::table('anggota')->insert(['id' => 10, 'kode_anggota' => 'AGT-UJI', 'nama_lengkap' => 'Anggota Uji', 'status' => 'aktif', 'tanggal_daftar' => '2026-11-01']);
        DB::table('jenis_simpanan')->insert(['id' => 1, 'kode_jenis' => 'SUKA', 'nama_jenis' => 'Sukarela']);
        DB::table('kas')->insert([
            'jenis' => 'masuk', 'jumlah' => 125000, 'saldo_sesudah' => 125000,
            'keterangan' => 'Kas uji laporan', 'tanggal' => '2026-11-04 09:00:00',
        ]);
        DB::table('transaksi')->insert(['kode_transaksi' => 'SET-UJI', 'anggota_id' => 10, 'jenis_simpanan_id' => 1, 'jenis_transaksi' => 'setoran', 'jumlah' => 50000, 'tanggal_transaksi' => '2026-11-04 09:00:00']);
        DB::table('waserda_transaksi')->insert(['kode_transaksi' => 'WSD-UJI', 'anggota_id' => 10, 'total_bayar' => 25000, 'metode_bayar' => 'tunai', 'created_at' => '2026-11-04 09:00:00']);
        DB::table('jenis_pinjaman')->insert(['id' => 1, 'nama_jenis' => 'Umum']);
        DB::table('pinjaman')->insert([
            'id' => 1,
            'kode_pinjaman' => 'PJN-UJI',
            'anggota_id' => 10,
            'jenis_pinjaman_id' => 1,
            'status' => 'lunas',
            'tanggal_lunas' => '2026-11-04',
        ]);
        DB::table('angsuran')->insert(['pinjaman_id' => 1, 'angsuran_ke' => 1, 'denda' => 1000, 'status' => 'terlambat', 'tanggal_jatuh_tempo' => '2026-11-04']);
    }

    public function test_admin_can_filter_cash_report_and_export_csv_with_audit(): void
    {
        $admin = User::query()->findOrFail(1);

        $this->actingAs($admin)
            ->get(route('portal.reports.index', ['role' => 'admin', 'jenis' => 'kas', 'q' => 'uji', 'per_page' => 5]))
            ->assertOk()
            ->assertSee('Kas uji laporan')
            ->assertSee('Laporan kas');

        $response = $this->actingAs($admin)
            ->get(route('portal.reports.export', ['role' => 'admin', 'jenis' => 'kas', 'q' => 'uji']));

        $response->assertOk();
        $this->assertStringContainsString('Kas uji laporan', $response->streamedContent());
        $this->assertDatabaseHas('activity_logs', ['user_id' => 1, 'action' => 'report.exported', 'auditable_type' => 'laporan']);
    }

    public function test_teller_cannot_open_or_export_admin_reports(): void
    {
        $teller = User::query()->findOrFail(2);

        $this->actingAs($teller)->get(route('portal.reports.index', ['role' => 'admin']))->assertForbidden();
        $this->actingAs($teller)->get(route('portal.reports.export', ['role' => 'admin']))->assertForbidden();
    }

    public function test_teller_report_types_match_native_acl(): void
    {
        $teller = User::query()->findOrFail(2);
        $tellerTypes = (new \ReflectionClass(\App\Http\Controllers\Portal\ReportController::class))
            ->getConstant('TELLER_TYPES');

        $this->assertContains('lunas', $tellerTypes);
        $this->assertContains('denda', $tellerTypes);
        $this->assertNotContains('pinjaman', $tellerTypes);
        $this->assertNotContains('angsuran', $tellerTypes);

        $this->actingAs($teller)
            ->get(route('portal.reports.index', ['role' => 'teller', 'jenis' => 'denda']))
            ->assertOk();

        $this->actingAs($teller)
            ->get(route('portal.reports.index', ['role' => 'teller', 'jenis' => 'pinjaman']))
            ->assertSessionHasErrors('jenis');

        $this->actingAs($teller)
            ->get(route('portal.reports.index', ['role' => 'teller', 'jenis' => 'angsuran']))
            ->assertSessionHasErrors('jenis');
    }

    public function test_all_phase_six_report_types_render_for_admin(): void
    {
        $admin = User::query()->findOrFail(1);
        $titles = [
            'simpanan' => 'Laporan simpanan',
            'kas' => 'Laporan kas',
            'anggota' => 'Laporan anggota',
            'waserda' => 'Laporan Waserda',
            'denda' => 'Laporan denda',
        ];

        foreach ($titles as $jenis => $title) {
            $this->actingAs($admin)
                ->get(route('portal.reports.index', ['role' => 'admin', 'jenis' => $jenis, 'tanggal_dari' => '2026-11-01', 'tanggal_sampai' => '2026-11-04']))
                ->assertOk()
                ->assertSee($title);
        }
    }
}
