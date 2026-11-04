<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JenisPinjaman extends Model
{
    protected $table = 'jenis_pinjaman';

    public $timestamps = false;

    protected $fillable = ['kode_jenis', 'nama_jenis', 'maksimal_pinjaman', 'tenor_max', 'bunga', 'frekuensi_diizinkan', 'memerlukan_jaminan', 'deskripsi', 'status'];

    protected function casts(): array
    {
        return ['maksimal_pinjaman' => 'decimal:2', 'bunga' => 'decimal:2', 'memerlukan_jaminan' => 'boolean'];
    }
}
