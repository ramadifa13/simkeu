<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addIndexIfMissing('angsuran', 'angsuran_pinjaman_id_status_index', ['pinjaman_id', 'status']);
        $this->addIndexIfMissing('angsuran', 'angsuran_tanggal_jatuh_tempo_index', ['tanggal_jatuh_tempo']);
        $this->addIndexIfMissing('angsuran', 'angsuran_tanggal_bayar_index', ['tanggal_bayar']);
        $this->addIndexIfMissing('transaksi', 'transaksi_tanggal_transaksi_index', ['tanggal_transaksi']);
        $this->addIndexIfMissing('transaksi', 'transaksi_anggota_id_jenis_simpanan_id_index', ['anggota_id', 'jenis_simpanan_id']);
        $this->addIndexIfMissing('kas', 'kas_tanggal_index', ['tanggal']);
        $this->addIndexIfMissing('kas', 'kas_transaksi_id_index', ['transaksi_id']);
        $this->addIndexIfMissing('pinjaman', 'pinjaman_anggota_id_status_index', ['anggota_id', 'status']);
        $this->addIndexIfMissing('pengajuan_pinjaman', 'pengajuan_pinjaman_status_tanggal_index', ['status', 'tanggal_pengajuan']);
        $this->addIndexIfMissing('wa_pengingat_log', 'wa_pengingat_log_angsuran_id_index', ['angsuran_id']);
    }

    public function down(): void
    {
        $this->dropIndexIfExists('angsuran', 'angsuran_pinjaman_id_status_index');
        $this->dropIndexIfExists('angsuran', 'angsuran_tanggal_jatuh_tempo_index');
        $this->dropIndexIfExists('angsuran', 'angsuran_tanggal_bayar_index');
        $this->dropIndexIfExists('transaksi', 'transaksi_tanggal_transaksi_index');
        $this->dropIndexIfExists('transaksi', 'transaksi_anggota_id_jenis_simpanan_id_index');
        $this->dropIndexIfExists('kas', 'kas_tanggal_index');
        $this->dropIndexIfExists('kas', 'kas_transaksi_id_index');
        $this->dropIndexIfExists('pinjaman', 'pinjaman_anggota_id_status_index');
        $this->dropIndexIfExists('pengajuan_pinjaman', 'pengajuan_pinjaman_status_tanggal_index');
        $this->dropIndexIfExists('wa_pengingat_log', 'wa_pengingat_log_angsuran_id_index');
    }

    /** @param list<string> $columns */
    private function addIndexIfMissing(string $table, string $indexName, array $columns): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }
        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) {
                return;
            }
        }
        if ($this->indexExists($table, $indexName)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $indexName): void {
            $blueprint->index($columns, $indexName);
        });
    }

    private function dropIndexIfExists(string $table, string $indexName): void
    {
        if (! Schema::hasTable($table) || ! $this->indexExists($table, $indexName)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($indexName): void {
            $blueprint->dropIndex($indexName);
        });
    }

    private function indexExists(string $table, string $indexName): bool
    {
        return collect(Schema::getIndexes($table))->contains(
            fn (array $index): bool => ($index['name'] ?? '') === $indexName
        );
    }
};
