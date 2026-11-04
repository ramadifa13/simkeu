<?php

namespace App\Actions\Migration;

use App\Models\User;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

/** Idempotent migration import with dry-run and failure report. */
class ImportMigrationWorkbookAction
{
    /**
     * @return array{dry_run: bool, imported: array{nasabah:int, pinjaman:int}, skipped: array{nasabah:int, pinjaman:int}, failures: list<array{sheet:string,row:int,message:string}>}
     */
    public function handle(UploadedFile $file, bool $dryRun, User $actor): array
    {
        if ($actor->role !== 'admin') {
            throw new DomainException('Migrasi hanya untuk Admin.');
        }

        $spreadsheet = IOFactory::load($file->getRealPath());
        $meta = $spreadsheet->getSheetByName('Meta');
        if (! $meta) {
            throw new DomainException('Sheet Meta tidak ditemukan.');
        }
        $version = null;
        foreach ($meta->toArray() as $row) {
            if (($row[0] ?? '') === 'versi_template') {
                $version = (string) ($row[1] ?? '');
            }
        }
        if ($version !== BuildMigrationTemplateAction::VERSION) {
            throw new DomainException('Versi template migrasi tidak dikenali. Unduh template terbaru.');
        }

        $result = [
            'dry_run' => $dryRun,
            'imported' => ['nasabah' => 0, 'pinjaman' => 0],
            'skipped' => ['nasabah' => 0, 'pinjaman' => 0],
            'failures' => [],
        ];

        $runner = function () use ($spreadsheet, $dryRun, &$result): void {
            $members = $spreadsheet->getSheetByName('Nasabah');
            if ($members) {
                $rows = $members->toArray();
                array_shift($rows);
                foreach ($rows as $index => $row) {
                    $line = $index + 2;
                    $code = trim((string) ($row[0] ?? ''));
                    if ($code === '') {
                        continue;
                    }
                    try {
                        if (DB::table('anggota')->where('kode_anggota', $code)->exists()) {
                            $result['skipped']['nasabah']++;
                            continue;
                        }
                        if (! $dryRun) {
                            DB::table('anggota')->insert([
                                'kode_anggota' => $code,
                                'ktp' => (string) ($row[1] ?? ''),
                                'nama_lengkap' => (string) ($row[2] ?? ''),
                                'alamat' => (string) ($row[3] ?? ''),
                                'telepon' => (string) ($row[4] ?? ''),
                                'status' => in_array(($row[5] ?? 'aktif'), ['aktif', 'nonaktif'], true) ? $row[5] : 'aktif',
                                'tanggal_daftar' => $this->date((string) ($row[6] ?? '')) ?? now()->toDateString(),
                            ]);
                        }
                        $result['imported']['nasabah']++;
                    } catch (\Throwable $exception) {
                        $result['failures'][] = ['sheet' => 'Nasabah', 'row' => $line, 'message' => $exception->getMessage()];
                    }
                }
            }

            $loans = $spreadsheet->getSheetByName('Pinjaman');
            if ($loans) {
                $rows = $loans->toArray();
                array_shift($rows);
                foreach ($rows as $index => $row) {
                    $line = $index + 2;
                    $code = trim((string) ($row[0] ?? ''));
                    if ($code === '') {
                        continue;
                    }
                    try {
                        if (DB::table('pinjaman')->where('kode_pinjaman', $code)->exists()) {
                            $result['skipped']['pinjaman']++;
                            continue;
                        }
                        $memberId = DB::table('anggota')->where('kode_anggota', (string) ($row[1] ?? ''))->value('id');
                        $typeId = DB::table('jenis_pinjaman')->where('kode_jenis', (string) ($row[2] ?? ''))->value('id')
                            ?? DB::table('jenis_pinjaman')->orderBy('id')->value('id');
                        if (! $memberId || ! $typeId) {
                            throw new DomainException('Anggota atau jenis pinjaman tidak ditemukan.');
                        }
                        if (! $dryRun) {
                            DB::table('pinjaman')->insert([
                                'kode_pinjaman' => $code,
                                'anggota_id' => $memberId,
                                'jenis_pinjaman_id' => $typeId,
                                'jumlah_pinjaman' => (float) ($row[3] ?? 0),
                                'tenor' => (int) ($row[4] ?? 0),
                                'tanggal_pencairan' => $this->date((string) ($row[5] ?? '')) ?? now()->toDateString(),
                                'status' => in_array(($row[6] ?? 'aktif'), ['aktif', 'lunas'], true) ? $row[6] : 'aktif',
                                'sisa_pokok' => (float) ($row[3] ?? 0),
                            ]);
                        }
                        $result['imported']['pinjaman']++;
                    } catch (\Throwable $exception) {
                        $result['failures'][] = ['sheet' => 'Pinjaman', 'row' => $line, 'message' => $exception->getMessage()];
                    }
                }
            }
        };

        if ($dryRun) {
            $runner();
        } else {
            DB::transaction($runner, 3);
        }

        return $result;
    }

    private function date(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $value;
        }
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $value, $m)) {
            return $m[3].'-'.$m[2].'-'.$m[1];
        }

        return null;
    }
}
