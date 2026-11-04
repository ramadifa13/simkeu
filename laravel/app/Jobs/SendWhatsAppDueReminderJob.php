<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendWhatsAppDueReminderJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $installmentId) {}

    public function handle(): void
    {
        $phoneId = (string) config('services.whatsapp.phone_number_id');
        $token = (string) config('services.whatsapp.access_token');
        $graphVersion = (string) config('services.whatsapp.graph_version');
        $template = (string) config('services.whatsapp.template_name', 'pengingat_angsuran_h1');
        $language = (string) config('services.whatsapp.template_lang', 'id');
        if ($phoneId === '' || $token === '' || ! preg_match('/^v\d+\.\d+$/D', $graphVersion)) {
            throw new \RuntimeException('Konfigurasi WhatsApp belum lengkap.');
        }

        $row = DB::table('angsuran as a')
            ->join('pinjaman as p', 'p.id', '=', 'a.pinjaman_id')
            ->join('anggota as ag', 'ag.id', '=', 'p.anggota_id')
            ->where('a.id', $this->installmentId)
            ->select(['a.id', 'a.angsuran_ke', 'a.tanggal_jatuh_tempo', 'a.jumlah_angsuran', 'p.kode_pinjaman', 'ag.nama_lengkap', 'ag.telepon'])
            ->first();
        if (! $row) {
            return;
        }

        $already = DB::table('wa_pengingat_log')
            ->where('angsuran_id', $row->id)
            ->whereDate('tanggal_jadwal', $row->tanggal_jatuh_tempo)
            ->where('status', 'terkirim')
            ->exists();
        if ($already) {
            return;
        }

        $number = $this->normalize((string) $row->telepon);
        if ($number === '') {
            $this->log($row->id, (string) $row->tanggal_jatuh_tempo, '', 'dilewati', null, 'Nomor tidak valid');

            return;
        }

        try {
            $messageId = $this->send($graphVersion, $phoneId, $token, $template, $language, $number, [
                $row->nama_lengkap,
                (string) $row->angsuran_ke,
                now()->parse($row->tanggal_jatuh_tempo)->format('d-m-Y'),
                'Rp '.number_format((float) $row->jumlah_angsuran, 0, ',', '.'),
                $row->kode_pinjaman,
            ]);
            $this->log($row->id, (string) $row->tanggal_jatuh_tempo, $number, 'terkirim', $messageId, null);
        } catch (Throwable $exception) {
            $this->log($row->id, (string) $row->tanggal_jatuh_tempo, $number, 'gagal', null, mb_substr($exception->getMessage(), 0, 500));
            throw $exception;
        }
    }

    private function normalize(string $number): string
    {
        $number = preg_replace('/\D+/', '', $number) ?? '';
        if (str_starts_with($number, '0')) {
            $number = '62'.substr($number, 1);
        }

        return preg_match('/^62\d{8,13}$/D', $number) ? $number : '';
    }

    /** @param  list<string>  $params */
    private function send(string $graphVersion, string $phoneId, string $token, string $template, string $language, string $to, array $params): string
    {
        $values = array_map(fn (string $value): array => ['type' => 'text', 'text' => $value], $params);
        $response = Http::withToken($token)
            ->timeout(30)
            ->post('https://graph.facebook.com/'.rawurlencode($graphVersion).'/'.rawurlencode($phoneId).'/messages', [
                'messaging_product' => 'whatsapp',
                'to' => $to,
                'type' => 'template',
                'template' => [
                    'name' => $template,
                    'language' => ['code' => $language],
                    'components' => [['type' => 'body', 'parameters' => $values]],
                ],
            ]);
        if (! $response->successful()) {
            throw new \RuntimeException('WhatsApp API HTTP '.$response->status().': '.($response->json('error.message') ?? 'respons tidak valid'));
        }

        return (string) ($response->json('messages.0.id') ?? '');
    }

    private function log(int $installmentId, string $dueDate, string $number, string $status, ?string $messageId, ?string $error): void
    {
        $existing = DB::table('wa_pengingat_log')->where('angsuran_id', $installmentId)->whereDate('tanggal_jadwal', $dueDate)->first();
        if ($existing) {
            DB::table('wa_pengingat_log')->where('id', $existing->id)->update([
                'nomor' => $number,
                'status' => $status,
                'message_id' => $messageId,
                'error_message' => $error,
                'percobaan' => ((int) $existing->percobaan) + 1,
                'sent_at' => $status === 'terkirim' ? now() : $existing->sent_at,
                'updated_at' => now(),
            ]);

            return;
        }
        DB::table('wa_pengingat_log')->insert([
            'angsuran_id' => $installmentId,
            'tanggal_jadwal' => $dueDate,
            'nomor' => $number,
            'status' => $status,
            'message_id' => $messageId,
            'error_message' => $error,
            'percobaan' => 1,
            'sent_at' => $status === 'terkirim' ? now() : null,
            'updated_at' => now(),
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('WhatsApp reminder failed', ['installment_id' => $this->installmentId, 'error' => $exception?->getMessage()]);
    }
}
