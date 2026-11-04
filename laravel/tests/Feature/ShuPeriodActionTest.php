<?php

namespace Tests\Feature;

use App\Actions\Shu\ProcessShuAction;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ShuPeriodActionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('shu_periode', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('tahun')->unique();
            $table->string('nama_periode');
            $table->date('tanggal_mulai');
            $table->date('tanggal_selesai');
            $table->string('status')->default('draft');
            $table->decimal('total_shu', 15, 0)->default(0);
            $table->text('kebijakan_json');
            $table->text('alokasi_json')->nullable();
            $table->string('snapshot_hash', 64)->nullable();
            $table->string('referensi_rat')->nullable();
            $table->unsignedInteger('dibuat_oleh');
            $table->dateTime('dihitung_at')->nullable();
            $table->unsignedInteger('disahkan_oleh')->nullable();
            $table->dateTime('disahkan_at')->nullable();
            $table->dateTime('created_at')->nullable();
        });
        Schema::create('shu_anggota', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('shu_periode_id');
            $table->unsignedInteger('anggota_id');
            $table->string('nama_snapshot');
            $table->string('kode_snapshot');
            $table->decimal('total_simpanan', 18, 2)->default(0);
            $table->decimal('total_pinjaman', 18, 2)->default(0);
            $table->decimal('total_transaksi', 18, 2)->default(0);
            $table->decimal('jasa_simpanan', 15, 0)->default(0);
            $table->decimal('jasa_pinjaman', 15, 0)->default(0);
            $table->decimal('jasa_transaksi', 15, 0)->default(0);
            $table->decimal('total_shu', 15, 0)->default(0);
            $table->string('status_bayar')->default('belum');
            $table->dateTime('tanggal_bayar')->nullable();
            $table->unsignedBigInteger('pembayaran_id')->nullable();
        });
        Schema::create('shu_pembayaran', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('shu_anggota_id');
            $table->decimal('jumlah', 15, 0);
            $table->string('metode');
            $table->string('bukti');
            $table->string('referensi');
            $table->string('nonce', 32)->unique();
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('transaksi_id');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('dibatalkan_at')->nullable();
            $table->unsignedInteger('dibatalkan_oleh')->nullable();
            $table->string('alasan_batal')->nullable();
            $table->unsignedInteger('transaksi_balik_id')->nullable();
        });
        Schema::create('shu_audit', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('periode_id');
            $table->unsignedInteger('user_id');
            $table->string('aksi');
            $table->text('detail');
            $table->dateTime('created_at')->nullable();
        });
        Schema::create('anggota', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('nama_lengkap');
            $table->string('kode_anggota');
            $table->date('tanggal_daftar')->nullable();
        });
        Schema::create('jenis_simpanan', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('kode_jenis');
            $table->string('nama_jenis');
            $table->string('deskripsi')->nullable();
            $table->string('status')->default('aktif');
        });
        Schema::create('transaksi', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('kode_transaksi');
            $table->unsignedInteger('anggota_id');
            $table->unsignedInteger('jenis_simpanan_id');
            $table->string('jenis_transaksi');
            $table->decimal('jumlah', 15, 2);
            $table->decimal('saldo_sebelum', 15, 2)->default(0);
            $table->decimal('saldo_sesudah', 15, 2)->default(0);
            $table->string('keterangan')->nullable();
            $table->unsignedInteger('user_id')->nullable();
            $table->dateTime('tanggal_transaksi')->nullable();
        });
        Schema::create('kas', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('transaksi_id')->nullable();
            $table->string('jenis');
            $table->decimal('jumlah', 15, 2);
            $table->decimal('saldo_sebelum', 15, 2);
            $table->decimal('saldo_sesudah', 15, 2);
            $table->string('keterangan');
            $table->dateTime('tanggal');
        });
        Schema::create('angsuran', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('pinjaman_id');
            $table->decimal('bunga', 15, 2)->default(0);
            $table->string('status');
            $table->date('tanggal_bayar')->nullable();
        });
        Schema::create('pinjaman', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('anggota_id');
            $table->string('status');
        });

        DB::table('anggota')->insert(['id' => 1, 'nama_lengkap' => 'Anggota A', 'kode_anggota' => 'AGT-1', 'tanggal_daftar' => '2024-01-01']);
        DB::table('jenis_simpanan')->insert(['id' => 1, 'kode_jenis' => 'SW', 'nama_jenis' => 'Wajib', 'status' => 'aktif']);
        DB::table('transaksi')->insert([
            'kode_transaksi' => 'TRX-1',
            'anggota_id' => 1,
            'jenis_simpanan_id' => 1,
            'jenis_transaksi' => 'setoran',
            'jumlah' => 1000000,
            'tanggal_transaksi' => '2024-06-01 10:00:00',
        ]);
        DB::table('kas')->insert(['jenis' => 'masuk', 'jumlah' => 5000000, 'saldo_sebelum' => 0, 'saldo_sesudah' => 5000000, 'keterangan' => 'Modal', 'tanggal' => now()]);
    }

    public function test_create_calculate_and_approve_shu_period(): void
    {
        $admin = new User(['role' => 'admin']);
        $admin->id = 1;
        $percentages = [
            'persen_simpanan' => 80,
            'persen_pinjaman' => 0,
            'persen_transaksi' => 0,
            'persen_cadangan' => 20,
            'persen_pengurus' => 0,
            'persen_pengawas' => 0,
            'persen_sosial' => 0,
            'persen_pendidikan' => 0,
        ];

        $id = app(ProcessShuAction::class)->handle('buat', array_merge([
            'tahun' => 2024,
            'total_shu' => 1000000,
            'basis_simpanan' => 'saldo_akhir',
        ], $percentages), $admin);

        app(ProcessShuAction::class)->handle('hitung', ['periode_id' => $id], $admin);
        $this->assertDatabaseHas('shu_periode', ['id' => $id, 'status' => 'dihitung']);
        $this->assertDatabaseHas('shu_anggota', ['shu_periode_id' => $id, 'anggota_id' => 1]);

        app(ProcessShuAction::class)->handle('sahkan', [
            'periode_id' => $id,
            'referensi_rat' => 'RAT 2025 nomor 01',
            'konfirmasi_rat' => 1,
        ], $admin);
        $this->assertDatabaseHas('shu_periode', ['id' => $id, 'status' => 'final']);
    }
}
