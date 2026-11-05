<?php

namespace Tests\Feature;

use App\Actions\Migration\BuildMigrationTemplateAction;
use App\Actions\Migration\ImportMigrationWorkbookAction;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class MigrationImportActionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('anggota', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('kode_anggota')->unique();
            $table->string('ktp')->nullable();
            $table->string('nama_lengkap');
            $table->string('alamat')->nullable();
            $table->string('telepon')->nullable();
            $table->string('status')->default('aktif');
            $table->date('tanggal_daftar')->nullable();
        });
        Schema::create('jenis_pinjaman', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('kode_jenis');
            $table->string('nama_jenis')->nullable();
        });
        Schema::create('pinjaman', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('kode_pinjaman')->unique();
            $table->unsignedInteger('anggota_id');
            $table->unsignedInteger('jenis_pinjaman_id');
            $table->decimal('jumlah_pinjaman', 15, 2)->default(0);
            $table->unsignedInteger('tenor')->default(0);
            $table->date('tanggal_pencairan')->nullable();
            $table->string('status')->default('aktif');
            $table->decimal('sisa_pokok', 15, 2)->default(0);
        });
        DB::table('jenis_pinjaman')->insert(['id' => 1, 'kode_jenis' => 'REG', 'nama_jenis' => 'Reguler']);
    }

    public function test_dry_run_and_idempotent_import(): void
    {
        $path = storage_path('framework/testing/migrasi-test.xlsx');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        $spreadsheet = new Spreadsheet;
        $meta = $spreadsheet->getActiveSheet();
        $meta->setTitle('Meta');
        $meta->fromArray([['kunci', 'nilai'], ['versi_template', BuildMigrationTemplateAction::VERSION]]);
        $nasabah = $spreadsheet->createSheet();
        $nasabah->setTitle('Nasabah');
        $nasabah->fromArray([
            ['kode_anggota', 'ktp', 'nama_lengkap', 'alamat', 'telepon', 'status', 'tanggal_daftar'],
            ['AGT-MIG-1', '123', 'Nasabah Migrasi', 'Alamat', '081', 'aktif', '2024-01-01'],
        ]);
        $pinjaman = $spreadsheet->createSheet();
        $pinjaman->setTitle('Pinjaman');
        $pinjaman->fromArray([
            ['kode_pinjaman', 'kode_anggota', 'kode_jenis', 'jumlah_pinjaman', 'tenor', 'tanggal_pencairan', 'status'],
            ['PJN-MIG-1', 'AGT-MIG-1', 'REG', 1000000, 12, '2024-02-01', 'aktif'],
        ]);
        (new Xlsx($spreadsheet))->save($path);

        $admin = $this->makeUser('admin');
        $admin->id = 1;
        $file = new UploadedFile($path, 'migrasi.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $dry = app(ImportMigrationWorkbookAction::class)->handle($file, true, $admin);
        $this->assertSame(1, $dry['imported']['nasabah']);
        $this->assertDatabaseMissing('anggota', ['kode_anggota' => 'AGT-MIG-1']);

        $import = app(ImportMigrationWorkbookAction::class)->handle($file, false, $admin);
        $this->assertSame(1, $import['imported']['nasabah']);
        $this->assertDatabaseHas('anggota', ['kode_anggota' => 'AGT-MIG-1']);

        $again = app(ImportMigrationWorkbookAction::class)->handle($file, false, $admin);
        $this->assertSame(0, $again['imported']['nasabah']);
        $this->assertSame(1, $again['skipped']['nasabah']);
        @unlink($path);
    }
}
