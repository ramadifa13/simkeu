<?php

namespace App\Console\Commands;

use App\Jobs\SendWhatsAppDueReminderJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RemindWhatsAppDueCommand extends Command
{
    protected $signature = 'wa:remind-due {--sync : Kirim langsung tanpa antrean}';

    protected $description = 'Kirim pengingat WhatsApp H-1 jatuh tempo angsuran dengan deduplikasi log';

    public function handle(): int
    {
        if (! Schema::hasTable('angsuran')) {
            $this->error('Tabel angsuran belum tersedia.');

            return self::FAILURE;
        }
        $this->ensureLogTable();
        $due = today()->addDay()->toDateString();
        $rows = DB::table('angsuran as a')
            ->join('pinjaman as p', 'p.id', '=', 'a.pinjaman_id')
            ->leftJoin('wa_pengingat_log as w', function ($join) use ($due): void {
                $join->on('w.angsuran_id', '=', 'a.id')
                    ->whereDate('w.tanggal_jadwal', $due)
                    ->where('w.status', 'terkirim');
            })
            ->where('p.status', 'aktif')
            ->where('a.status', '<>', 'lunas')
            ->whereDate('a.tanggal_jatuh_tempo', $due)
            ->whereNull('w.id')
            ->orderBy('a.id')
            ->pluck('a.id');

        foreach ($rows as $id) {
            if ($this->option('sync')) {
                SendWhatsAppDueReminderJob::dispatchSync((int) $id);
            } else {
                SendWhatsAppDueReminderJob::dispatch((int) $id);
            }
        }

        $this->info('Dijadwalkan '.$rows->count().' pengingat untuk jatuh tempo '.$due.'.');

        return self::SUCCESS;
    }

    private function ensureLogTable(): void
    {
        if (Schema::hasTable('wa_pengingat_log')) {
            return;
        }
        Schema::create('wa_pengingat_log', function ($table): void {
            $table->id();
            $table->unsignedInteger('angsuran_id');
            $table->date('tanggal_jadwal');
            $table->string('nomor', 20);
            $table->string('status', 20);
            $table->string('message_id', 150)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->unsignedInteger('percobaan')->default(1);
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['angsuran_id', 'tanggal_jadwal'], 'uniq_wa_angsuran_tanggal');
        });
    }
}
