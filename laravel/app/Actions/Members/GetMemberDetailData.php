<?php

namespace App\Actions\Members;

use App\Models\Anggota;
use Illuminate\Support\Facades\DB;

class GetMemberDetailData
{
    /**
     * @return array<string, mixed>
     */
    public function handle(Anggota $member, string $tab): array
    {
        if ($tab === 'simpanan') {
            return [
                'savings' => DB::table('simpanan')
                    ->join('jenis_simpanan', 'jenis_simpanan.id', '=', 'simpanan.jenis_simpanan_id')
                    ->where('simpanan.anggota_id', $member->id)
                    ->orderBy('jenis_simpanan.nama_jenis')
                    ->get(['jenis_simpanan.kode_jenis', 'jenis_simpanan.nama_jenis', 'simpanan.saldo']),
            ];
        }

        if ($tab === 'transaksi') {
            return [
                'transactions' => DB::table('transaksi')
                    ->join('jenis_simpanan', 'jenis_simpanan.id', '=', 'transaksi.jenis_simpanan_id')
                    ->where('transaksi.anggota_id', $member->id)
                    ->orderByDesc('transaksi.tanggal_transaksi')
                    ->limit(20)
                    ->get(['transaksi.kode_transaksi', 'transaksi.jenis_transaksi', 'transaksi.jumlah', 'transaksi.saldo_sesudah', 'transaksi.tanggal_transaksi', 'jenis_simpanan.nama_jenis']),
            ];
        }

        if ($tab === 'pinjaman') {
            $loans = DB::table('pinjaman')
                ->join('jenis_pinjaman', 'jenis_pinjaman.id', '=', 'pinjaman.jenis_pinjaman_id')
                ->where('pinjaman.anggota_id', $member->id)
                ->orderByDesc('pinjaman.created_at')
                ->get(['pinjaman.id', 'pinjaman.kode_pinjaman', 'pinjaman.jumlah_pinjaman', 'pinjaman.sisa_pokok', 'pinjaman.tenor', 'pinjaman.frekuensi', 'pinjaman.angsuran_per_bulan', 'pinjaman.tanggal_pencairan', 'pinjaman.jatuh_tempo', 'pinjaman.status', 'jenis_pinjaman.nama_jenis']);

            return [
                'installments' => DB::table('angsuran')
                    ->whereIn('pinjaman_id', $loans->pluck('id'))
                    ->orderBy('angsuran_ke')
                    ->get(['pinjaman_id', 'angsuran_ke', 'jumlah_angsuran', 'denda', 'tanggal_jatuh_tempo', 'tanggal_bayar', 'status']),
                'loans' => $loans,
            ];
        }

        if ($tab === 'shu') {
            $entitlements = DB::table('shu_anggota')
                ->join('shu_periode', 'shu_periode.id', '=', 'shu_anggota.shu_periode_id')
                ->where('shu_anggota.anggota_id', $member->id)
                ->orderByDesc('shu_periode.tahun')
                ->get([
                    'shu_anggota.id',
                    'shu_anggota.jasa_simpanan',
                    'shu_anggota.jasa_pinjaman',
                    'shu_anggota.jasa_transaksi',
                    'shu_anggota.total_shu',
                    'shu_anggota.status_bayar',
                    'shu_anggota.tanggal_bayar',
                    'shu_periode.tahun',
                    'shu_periode.nama_periode',
                    'shu_periode.status as periode_status',
                ]);

            return [
                'entitlements' => $entitlements,
                'payments' => DB::table('shu_pembayaran')
                    ->whereIn('shu_anggota_id', $entitlements->pluck('id'))
                    ->orderByDesc('created_at')
                    ->get(['shu_anggota_id', 'jumlah', 'metode', 'referensi', 'created_at', 'dibatalkan_at']),
                'loanRights' => DB::table('shu_hak_pinjaman')
                    ->where('anggota_id', $member->id)
                    ->orderByDesc('created_at')
                    ->get(['id', 'jenis', 'kategori_snapshot', 'dasar_pinjaman', 'persen', 'jumlah', 'pembayaran_lancar', 'status_bayar', 'dibayar_at', 'created_at']),
            ];
        }

        if ($tab === 'audit') {
            return [
                'corrections' => DB::table('log_koreksi')
                    ->join('transaksi', 'transaksi.id', '=', 'log_koreksi.transaksi_id')
                    ->leftJoin('users', 'users.id', '=', 'log_koreksi.user_id')
                    ->where('transaksi.anggota_id', $member->id)
                    ->orderByDesc('log_koreksi.created_at')
                    ->get(['log_koreksi.jumlah_lama', 'log_koreksi.jumlah_baru', 'log_koreksi.selisih', 'log_koreksi.keterangan', 'log_koreksi.created_at', 'users.nama_lengkap as actor']),
                'loanActivities' => DB::table('layanan_audit')
                    ->join('pinjaman', 'pinjaman.id', '=', 'layanan_audit.pinjaman_id')
                    ->leftJoin('users', 'users.id', '=', 'layanan_audit.user_id')
                    ->where('pinjaman.anggota_id', $member->id)
                    ->orderByDesc('layanan_audit.created_at')
                    ->get(['pinjaman.kode_pinjaman', 'layanan_audit.aksi', 'layanan_audit.detail', 'layanan_audit.created_at', 'users.nama_lengkap as actor']),
                'loanRightActivities' => DB::table('shu_hak_audit')
                    ->join('shu_hak_pinjaman', 'shu_hak_pinjaman.id', '=', 'shu_hak_audit.hak_id')
                    ->leftJoin('users', 'users.id', '=', 'shu_hak_audit.user_id')
                    ->where('shu_hak_pinjaman.anggota_id', $member->id)
                    ->orderByDesc('shu_hak_audit.created_at')
                    ->get(['shu_hak_audit.aksi', 'shu_hak_audit.detail', 'shu_hak_audit.created_at', 'users.nama_lengkap as actor']),
            ];
        }

        return [];
    }
}
