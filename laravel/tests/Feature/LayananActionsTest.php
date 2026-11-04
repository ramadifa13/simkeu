<?php

namespace Tests\Feature;

use App\Actions\Layanan\DecideDelayDisbursementAction;
use App\Actions\Layanan\ProcessLayananAction;
use App\Actions\Layanan\RequestDelayDisbursementAction;
use App\Models\User;
use DomainException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LayananActionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('role');
            $table->string('nama_lengkap')->nullable();
        });
        Schema::create('anggota', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('nama_lengkap');
            $table->string('kode_anggota');
            $table->string('telepon')->nullable();
        });
        Schema::create('jenis_pinjaman', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('nama_jenis');
            $table->boolean('memerlukan_jaminan')->default(false);
        });
        Schema::create('pinjaman', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('kode_pinjaman');
            $table->unsignedInteger('anggota_id');
            $table->unsignedInteger('jenis_pinjaman_id');
            $table->string('status');
            $table->decimal('sisa_pokok', 15, 2)->default(0);
            $table->date('jatuh_tempo')->nullable();
            $table->date('tanggal_lunas')->nullable();
        });
        Schema::create('angsuran', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('pinjaman_id');
            $table->unsignedInteger('angsuran_ke');
            $table->decimal('pokok', 15, 2)->default(0);
            $table->decimal('bunga', 15, 2)->default(0);
            $table->decimal('jumlah_angsuran', 15, 2)->default(0);
            $table->decimal('denda', 15, 2)->default(0);
            $table->boolean('denda_manual')->default(false);
            $table->string('status');
            $table->date('tanggal_jatuh_tempo');
            $table->date('tanggal_bayar')->nullable();
        });
        Schema::create('layanan_laporan_macet', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('pinjaman_id');
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('hari_telat')->default(0);
            $table->decimal('sisa_hutang', 15, 2)->default(0);
            $table->text('catatan');
            $table->string('status');
            $table->text('keputusan')->nullable();
            $table->unsignedInteger('admin_id')->nullable();
            $table->dateTime('diputuskan_at')->nullable();
            $table->dateTime('created_at')->nullable();
        });
        Schema::create('layanan_pengajuan_hapus_denda', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('pinjaman_id');
            $table->unsignedInteger('angsuran_id');
            $table->unsignedInteger('user_id');
            $table->text('alasan');
            $table->string('status');
            $table->boolean('aktif')->nullable();
            $table->unsignedInteger('admin_id')->nullable();
            $table->dateTime('diputuskan_at')->nullable();
            $table->dateTime('created_at')->nullable();
        });
        Schema::create('layanan_prioritas', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('pinjaman_id')->unique();
            $table->unsignedInteger('anggota_id');
            $table->unsignedInteger('user_id');
            $table->text('catatan')->nullable();
            $table->string('status');
            $table->unsignedInteger('admin_id')->nullable();
            $table->text('koreksi')->nullable();
            $table->unsignedInteger('koreksi_admin_id')->nullable();
            $table->dateTime('koreksi_at')->nullable();
            $table->dateTime('created_at')->nullable();
        });
        Schema::create('layanan_pengaturan', function (Blueprint $table): void {
            $table->increments('id');
            $table->boolean('pemutihan')->default(true);
            $table->decimal('bonus_persen', 8, 2)->default(0);
        });
        Schema::create('layanan_bayar_denda', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('angsuran_id');
            $table->unsignedInteger('pinjaman_id');
            $table->decimal('jumlah', 15, 2);
        });
        Schema::create('layanan_audit', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('pinjaman_id');
            $table->unsignedInteger('user_id');
            $table->string('aksi');
            $table->text('detail');
            $table->dateTime('created_at')->nullable();
        });
        Schema::create('pengajuan_pinjaman', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('kode_pengajuan');
            $table->unsignedInteger('anggota_id');
            $table->string('status');
            $table->text('catatan_admin')->nullable();
            $table->unsignedInteger('admin_id')->nullable();
            $table->dateTime('tanggal_persetujuan')->nullable();
        });
        Schema::create('layanan_tunda_cair', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('pengajuan_id');
            $table->unsignedInteger('user_id');
            $table->string('status');
            $table->text('alasan');
            $table->unsignedInteger('admin_id')->nullable();
            $table->text('catatan_admin')->nullable();
            $table->dateTime('diputuskan_at')->nullable();
            $table->dateTime('created_at')->nullable();
        });

        DB::table('jenis_pinjaman')->insert(['id' => 1, 'nama_jenis' => 'Reguler', 'memerlukan_jaminan' => false]);
        DB::table('anggota')->insert(['id' => 1, 'nama_lengkap' => 'Anggota Uji', 'kode_anggota' => 'AGT-1', 'telepon' => '081']);
        DB::table('layanan_pengaturan')->insert(['id' => 1, 'pemutihan' => true, 'bonus_persen' => 0]);
        DB::table('pinjaman')->insert([
            'id' => 1,
            'kode_pinjaman' => 'PJN-1',
            'anggota_id' => 1,
            'jenis_pinjaman_id' => 1,
            'status' => 'aktif',
            'sisa_pokok' => 100000,
            'jatuh_tempo' => now()->subDays(10)->toDateString(),
        ]);
        DB::table('angsuran')->insert([
            'id' => 1,
            'pinjaman_id' => 1,
            'angsuran_ke' => 1,
            'pokok' => 100000,
            'bunga' => 10000,
            'jumlah_angsuran' => 110000,
            'denda' => 5000,
            'status' => 'telat',
            'tanggal_jatuh_tempo' => now()->subDays(10)->toDateString(),
        ]);
    }

    public function test_report_and_decide_default_workflow(): void
    {
        $teller = new User(['role' => 'teller']);
        $teller->id = 2;
        $admin = new User(['role' => 'admin']);
        $admin->id = 1;

        app(ProcessLayananAction::class)->handle(1, 'lapor_macet', ['catatan' => 'Nasabah tidak merespons telepon'], $teller);
        $this->assertDatabaseHas('layanan_laporan_macet', ['pinjaman_id' => 1, 'status' => 'menunggu']);

        $laporanId = (int) DB::table('layanan_laporan_macet')->value('id');
        app(ProcessLayananAction::class)->handle(1, 'keputusan_macet', [
            'laporan_id' => $laporanId,
            'keputusan' => 'ditindaklanjuti',
            'catatan' => 'Akan dikunjungi minggu ini',
        ], $admin);

        $this->assertDatabaseHas('layanan_laporan_macet', ['id' => $laporanId, 'status' => 'ditindaklanjuti']);
    }

    public function test_write_off_and_priority_require_admin_for_decision(): void
    {
        $teller = new User(['role' => 'teller']);
        $teller->id = 2;
        $admin = new User(['role' => 'admin']);
        $admin->id = 1;

        app(ProcessLayananAction::class)->handle(1, 'ajukan_hapus_denda', ['angsuran_id' => 1, 'catatan' => 'Kesulitan ekonomi keluarga'], $teller);
        $pengajuanId = (int) DB::table('layanan_pengajuan_hapus_denda')->value('id');

        try {
            app(ProcessLayananAction::class)->handle(1, 'putuskan_hapus_denda', ['pengajuan_id' => $pengajuanId, 'keputusan' => 'setuju'], $teller);
            $this->fail('Teller seharusnya tidak dapat memutuskan hapus denda.');
        } catch (DomainException) {
            $this->assertTrue(true);
        }

        app(ProcessLayananAction::class)->handle(1, 'putuskan_hapus_denda', ['pengajuan_id' => $pengajuanId, 'keputusan' => 'setuju'], $admin);
        $this->assertDatabaseHas('angsuran', ['id' => 1, 'denda' => 0, 'denda_manual' => 1]);

        $dueOne = today()->subMonths(1)->toDateString();
        $dueTwo = today()->subMonths(2)->toDateString();
        DB::table('pinjaman')->where('id', 1)->update(['status' => 'lunas', 'tanggal_lunas' => $dueOne, 'jatuh_tempo' => $dueOne]);
        DB::table('angsuran')->where('id', 1)->update(['status' => 'lunas', 'tanggal_jatuh_tempo' => $dueOne, 'tanggal_bayar' => $dueOne, 'denda' => 0]);
        DB::table('pinjaman')->insert([
            'id' => 2,
            'kode_pinjaman' => 'PJN-2',
            'anggota_id' => 1,
            'jenis_pinjaman_id' => 1,
            'status' => 'lunas',
            'sisa_pokok' => 0,
            'jatuh_tempo' => $dueTwo,
            'tanggal_lunas' => $dueTwo,
        ]);
        DB::table('angsuran')->insert([
            'id' => 2,
            'pinjaman_id' => 2,
            'angsuran_ke' => 1,
            'pokok' => 50000,
            'bunga' => 5000,
            'jumlah_angsuran' => 55000,
            'denda' => 0,
            'status' => 'lunas',
            'tanggal_jatuh_tempo' => $dueTwo,
            'tanggal_bayar' => $dueTwo,
        ]);

        app(ProcessLayananAction::class)->handle(1, 'prioritas', ['catatan' => 'Nasabah loyal dua kali lunas'], $teller);
        $this->assertDatabaseHas('layanan_prioritas', ['pinjaman_id' => 1, 'status' => 'menunggu']);
        app(ProcessLayananAction::class)->handle(1, 'approve_prioritas', ['catatan' => 'Layak'], $admin);
        $this->assertDatabaseHas('layanan_prioritas', ['pinjaman_id' => 1, 'status' => 'disetujui']);
    }

    public function test_delay_disbursement_request_and_admin_decision(): void
    {
        DB::table('pengajuan_pinjaman')->insert([
            'id' => 9,
            'kode_pengajuan' => 'PGJ-9',
            'anggota_id' => 1,
            'status' => 'disetujui',
        ]);
        $teller = new User(['role' => 'teller']);
        $teller->id = 2;
        $admin = new User(['role' => 'admin']);
        $admin->id = 1;

        app(RequestDelayDisbursementAction::class)->handle(9, 'Nasabah belum siap menerima dana', $teller);
        $this->assertDatabaseHas('layanan_tunda_cair', ['pengajuan_id' => 9, 'status' => 'menunggu']);
        $tcId = (int) DB::table('layanan_tunda_cair')->value('id');

        app(DecideDelayDisbursementAction::class)->handle($tcId, 'disetujui', 'Pengajuan dibatalkan sementara', $admin);
        $this->assertDatabaseHas('layanan_tunda_cair', ['id' => $tcId, 'status' => 'disetujui']);
        $this->assertDatabaseHas('pengajuan_pinjaman', ['id' => 9, 'status' => 'batal_cair']);
    }

    public function test_execute_and_sell_collateral_allocates_credit(): void
    {
        Schema::create('jaminan_pinjaman', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('pinjaman_id');
            $table->string('status_jaminan');
        });
        Schema::create('layanan_lelang', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('pinjaman_id');
            $table->unsignedInteger('user_id');
            $table->decimal('harga_jual', 15, 2);
            $table->decimal('kredit', 15, 2);
            $table->decimal('kelebihan', 15, 2);
            $table->decimal('dikembalikan', 15, 2)->default(0);
            $table->decimal('terpakai', 15, 2)->default(0);
            $table->string('metode');
            $table->string('bukti');
            $table->unsignedInteger('transaksi_id')->nullable();
            $table->text('catatan')->nullable();
            $table->dateTime('created_at')->nullable();
        });
        Schema::create('layanan_alokasi_lelang', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('lelang_id');
            $table->unsignedInteger('angsuran_id');
            $table->decimal('jumlah', 15, 2);
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

        DB::table('jenis_pinjaman')->where('id', 1)->update(['memerlukan_jaminan' => true]);
        DB::table('jaminan_pinjaman')->insert(['pinjaman_id' => 1, 'status_jaminan' => 'dititipkan']);
        $admin = new User(['role' => 'admin']);
        $admin->id = 1;

        app(ProcessLayananAction::class)->handle(1, 'eksekusi', ['catatan' => 'Nasabah wanprestasi setelah somasi'], $admin);
        $this->assertDatabaseHas('jaminan_pinjaman', ['pinjaman_id' => 1, 'status_jaminan' => 'dilelang']);

        app(ProcessLayananAction::class)->handle(1, 'jual_jaminan', [
            'jumlah' => 200000,
            'metode' => 'cash',
            'bukti' => 'layanan/bukti/test.webp',
            'catatan' => 'Terjual melalui lelang internal',
        ], $admin);

        $this->assertDatabaseHas('layanan_lelang', ['pinjaman_id' => 1]);
        $this->assertDatabaseHas('angsuran', ['id' => 1, 'status' => 'lunas']);
        $this->assertDatabaseHas('pinjaman', ['id' => 1, 'status' => 'lunas']);
    }
}
