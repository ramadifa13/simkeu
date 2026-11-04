<?php

namespace App\Actions\Migration;

use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Exports current operational rows into the versioned migration workbook format. */
class ExportMigrationWorkbookAction
{
    public function handle(User $actor): StreamedResponse
    {
        if ($actor->role !== 'admin') {
            throw new DomainException('Ekspor migrasi hanya untuk Admin.');
        }

        $spreadsheet = new Spreadsheet;
        $meta = $spreadsheet->getActiveSheet();
        $meta->setTitle('Meta');
        $meta->fromArray([
            ['kunci', 'nilai'],
            ['versi_template', BuildMigrationTemplateAction::VERSION],
            ['diekspor_at', now()->toDateTimeString()],
            ['catatan', 'Ekspor operasional tanpa file privat KTP/KK/TTD.'],
        ]);

        $members = $spreadsheet->createSheet();
        $members->setTitle('Nasabah');
        $members->fromArray([['kode_anggota', 'ktp', 'nama_lengkap', 'alamat', 'telepon', 'status', 'tanggal_daftar']]);
        $row = 2;
        foreach (DB::table('anggota')->orderBy('id')->limit(10000)->get() as $member) {
            $members->fromArray([[
                $member->kode_anggota,
                $member->ktp ?? '',
                $member->nama_lengkap,
                $member->alamat ?? '',
                $member->telepon ?? '',
                $member->status ?? 'aktif',
                $member->tanggal_daftar ?? '',
            ]], null, 'A'.$row);
            $row++;
        }

        $loans = $spreadsheet->createSheet();
        $loans->setTitle('Pinjaman');
        $loans->fromArray([['kode_pinjaman', 'kode_anggota', 'kode_jenis', 'jumlah_pinjaman', 'tenor', 'tanggal_pencairan', 'status']]);
        $row = 2;
        if (Schema::hasTable('pinjaman')) {
            $query = DB::table('pinjaman as p')
                ->join('anggota as a', 'a.id', '=', 'p.anggota_id')
                ->leftJoin('jenis_pinjaman as jp', 'jp.id', '=', 'p.jenis_pinjaman_id')
                ->orderBy('p.id')
                ->limit(10000)
                ->get(['p.kode_pinjaman', 'a.kode_anggota', 'jp.kode_jenis', 'p.jumlah_pinjaman', 'p.tenor', 'p.tanggal_pencairan', 'p.status']);
            foreach ($query as $loan) {
                $loans->fromArray([[
                    $loan->kode_pinjaman,
                    $loan->kode_anggota,
                    $loan->kode_jenis,
                    $loan->jumlah_pinjaman,
                    $loan->tenor,
                    $loan->tanggal_pencairan,
                    $loan->status,
                ]], null, 'A'.$row);
                $row++;
            }
        }

        $spreadsheet->setActiveSheetIndex(0);

        return response()->streamDownload(function () use ($spreadsheet): void {
            (new Xlsx($spreadsheet))->save('php://output');
        }, 'ekspor-migrasi-'.now()->format('Ymd-His').'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
