<?php

namespace App\Actions\Backup;

use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/** Admin-only operational SQL dump ZIP without private member/signature files. */
class CreateOperationalBackupAction
{
    /** @return array{token: string, filename: string, tables: int, bytes: int} */
    public function handle(User $actor): array
    {
        if ($actor->role !== 'admin') {
            throw new DomainException('Backup hanya untuk Admin.');
        }

        $tables = [
            'anggota', 'users', 'jenis_simpanan', 'jenis_pinjaman', 'jenis_pinjaman_tarif', 'denda_tarif',
            'pengajuan_pinjaman', 'pinjaman', 'angsuran', 'transaksi', 'kas', 'simpanan',
            'waserda_transaksi', 'koperasi', 'shu_periode', 'shu_anggota', 'shu_pembayaran', 'shu_hak_pinjaman',
            'layanan_audit', 'layanan_pembayaran', 'layanan_bayar_denda', 'layanan_lelang', 'layanan_prioritas',
            'layanan_tunda_cair', 'layanan_laporan_macet',
        ];
        $existing = array_values(array_filter($tables, fn (string $table): bool => Schema::hasTable($table)));
        if ($existing === []) {
            throw new DomainException('Tidak ada tabel operasional yang dapat dibackup.');
        }

        $token = bin2hex(random_bytes(16));
        $filename = 'backup-operasional-'.now()->format('Ymd-His').'.zip';
        $sqlName = 'dump.sql';
        $sql = "-- SIMKEU operational backup\n-- Generated at ".now()->toDateTimeString()."\n-- Excludes private member photos/signatures by design.\n\n";
        foreach ($existing as $table) {
            $sql .= $this->dumpTable($table);
        }

        $tempSql = storage_path('app/private/backups/'.$token.'-'.$sqlName);
        $tempZip = storage_path('app/private/backups/'.$token.'.zip');
        if (! is_dir(dirname($tempZip))) {
            mkdir(dirname($tempZip), 0755, true);
        }
        file_put_contents($tempSql, $sql);
        $zip = new ZipArchive;
        if ($zip->open($tempZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new DomainException('Gagal membuat arsip backup.');
        }
        $zip->addFile($tempSql, $sqlName);
        $zip->addFromString('README.txt', "Backup operasional SIMKEU\nTidak mencakup foto KTP/KK/TTD anggota.\nToken unduhan sekali pakai: {$token}\n");
        $zip->close();
        @unlink($tempSql);

        Storage::disk('local')->put('backups/'.$token.'.json', json_encode([
            'token' => $token,
            'filename' => $filename,
            'path' => $tempZip,
            'user_id' => $actor->id,
            'created_at' => now()->toDateTimeString(),
            'downloaded' => false,
            'tables' => $existing,
        ], JSON_THROW_ON_ERROR));

        return [
            'token' => $token,
            'filename' => $filename,
            'tables' => count($existing),
            'bytes' => (int) filesize($tempZip),
        ];
    }

    private function dumpTable(string $table): string
    {
        $rows = DB::table($table)->orderBy('id')->limit(20000)->get();
        $out = "-- table {$table}\n";
        if ($rows->isEmpty()) {
            return $out."-- empty\n\n";
        }
        foreach ($rows as $row) {
            $values = [];
            foreach ((array) $row as $value) {
                if ($value === null) {
                    $values[] = 'NULL';
                } elseif (is_numeric($value) && ! is_string($value)) {
                    $values[] = (string) $value;
                } else {
                    $values[] = "'".str_replace(["\\", "'"], ["\\\\", "\\'"], (string) $value)."'";
                }
            }
            $columns = array_map(fn ($c) => '`'.$c.'`', array_keys((array) $row));
            $out .= 'INSERT INTO `'.$table.'` ('.implode(',', $columns).') VALUES ('.implode(',', $values).");\n";
        }

        return $out."\n";
    }
}
