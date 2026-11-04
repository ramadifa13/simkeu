<?php

namespace App\Actions\Loans;

use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class CloseInstallmentPeriodAction
{
    public function preview(string $month): array
    {
        $this->validateMonth($month);
        [$start, $end] = [$month.'-01', now()->parse($month.'-01')->endOfMonth()->toDateString()];
        $rows = DB::table('angsuran')->where('status', 'lunas')->where('cara_bayar', 'cicilan')->whereBetween('tanggal_bayar', [$start, $end])->whereBetween('tanggal_jatuh_tempo', [$start, $end])->get(['id', 'jumlah_angsuran', 'denda', 'tanggal_bayar']);

        return ['jumlah' => $rows->count(), 'total' => round((float) $rows->sum(fn ($row) => $row->jumlah_angsuran + $row->denda), 2), 'rows' => $rows];
    }

    public function close(string $month, User $admin, string $note = ''): array
    {
        return DB::transaction(function () use ($month, $admin, $note): array {
            $this->validateMonth($month);
            $existing = DB::table('closing_angsuran_bulanan')->where('bulan', $month)->lockForUpdate()->first();
            if ($existing?->status === 'terkunci') {
                throw new DomainException('Periode ini sudah terkunci.');
            }
            $preview = $this->preview($month);
            $payload = ['status' => 'terkunci', 'ditutup_at' => now(), 'ditutup_oleh' => $admin->id, 'dibuka_at' => null, 'dibuka_oleh' => null, 'catatan' => $note, 'total_pembayaran' => $preview['total'], 'jumlah_pembayaran' => $preview['jumlah']];
            $id = $existing ? $existing->id : DB::table('closing_angsuran_bulanan')->insertGetId(['bulan' => $month, ...$payload]);
            if ($existing) {
                DB::table('closing_angsuran_bulanan')->where('id', $id)->update($payload);
                DB::table('closing_angsuran_detail')->where('closing_id', $id)->delete();
            }
            foreach ($preview['rows'] as $row) {
                DB::table('closing_angsuran_detail')->insert(['closing_id' => $id, 'angsuran_id' => $row->id, 'nominal' => $row->jumlah_angsuran + $row->denda, 'tanggal_bayar' => $row->tanggal_bayar]);
            }

            return ['id' => $id, 'jumlah' => $preview['jumlah'], 'total' => $preview['total']];
        }, 3);
    }

    public function reopen(string $month, User $admin, string $note): void
    {
        if (mb_strlen(trim($note)) < 5) {
            throw new DomainException('Alasan membuka periode minimal 5 karakter.');
        }
        DB::transaction(function () use ($month, $admin, $note): void {
            $row = DB::table('closing_angsuran_bulanan')->where('bulan', $month)->lockForUpdate()->first();
            if (! $row || $row->status !== 'terkunci') {
                throw new DomainException('Periode tidak terkunci.');
            }
            DB::table('closing_angsuran_detail')->where('closing_id', $row->id)->delete();
            DB::table('closing_angsuran_bulanan')->where('id', $row->id)->update(['status' => 'dibuka', 'dibuka_at' => now(), 'dibuka_oleh' => $admin->id, 'catatan' => trim($row->catatan.' | Dibuka: '.$note)]);
        }, 3);
    }

    private function validateMonth(string $month): void
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            throw new DomainException('Bulan closing tidak valid.');
        }
    }
}
