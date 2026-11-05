<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('kas') || Schema::hasColumn('kas', 'ref_type')) {
            return;
        }

        Schema::table('kas', function (Blueprint $table): void {
            $table->string('ref_type', 40)->nullable()->after('transaksi_id')->index();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('kas') || ! Schema::hasColumn('kas', 'ref_type')) {
            return;
        }

        Schema::table('kas', function (Blueprint $table): void {
            $table->dropIndex(['ref_type']);
            $table->dropColumn('ref_type');
        });
    }
};
