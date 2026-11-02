<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class LandingController extends Controller
{
    public function __invoke(): View
    {
        $cooperative = Schema::hasTable('koperasi')
            ? DB::table('koperasi')->latest('id')->first(['nama', 'alamat', 'telepon', 'email'])
            : null;

        $savings = Schema::hasTable('jenis_simpanan')
            ? DB::table('jenis_simpanan')->where('status', 'aktif')->orderBy('nama_jenis')
                ->get(['kode_jenis', 'nama_jenis', 'deskripsi', 'minimum', 'maksimum', 'bagi_hasil'])
            : collect();

        $loans = Schema::hasTable('jenis_pinjaman')
            ? DB::table('jenis_pinjaman')->where('status', 'aktif')->orderBy('nama_jenis')
                ->get(['kode_jenis', 'nama_jenis', 'deskripsi', 'maksimal_pinjaman', 'tenor_max', 'bunga', 'frekuensi_diizinkan', 'memerlukan_jaminan'])
            : collect();

        return view('landing', compact('cooperative', 'savings', 'loans'));
    }
}
