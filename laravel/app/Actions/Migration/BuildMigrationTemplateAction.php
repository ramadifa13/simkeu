<?php

namespace App\Actions\Migration;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Versioned blank XLSX template for Admin migration import. */
class BuildMigrationTemplateAction
{
    public const VERSION = 'simkeu-migrasi-v1';

    public function handle(): StreamedResponse
    {
        $spreadsheet = new Spreadsheet;
        $meta = $spreadsheet->getActiveSheet();
        $meta->setTitle('Meta');
        $meta->fromArray([
            ['kunci', 'nilai'],
            ['versi_template', self::VERSION],
            ['dibuat_at', now()->toDateTimeString()],
            ['catatan', 'Isi sheet Nasabah dan Pinjaman lalu unggah via dry-run sebelum impor final.'],
        ]);

        $members = $spreadsheet->createSheet();
        $members->setTitle('Nasabah');
        $members->fromArray([['kode_anggota', 'ktp', 'nama_lengkap', 'alamat', 'telepon', 'status', 'tanggal_daftar']]);

        $loans = $spreadsheet->createSheet();
        $loans->setTitle('Pinjaman');
        $loans->fromArray([['kode_pinjaman', 'kode_anggota', 'kode_jenis', 'jumlah_pinjaman', 'tenor', 'tanggal_pencairan', 'status']]);

        $spreadsheet->setActiveSheetIndex(0);

        return response()->streamDownload(function () use ($spreadsheet): void {
            (new Xlsx($spreadsheet))->save('php://output');
        }, 'template-migrasi-'.self::VERSION.'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }
}
