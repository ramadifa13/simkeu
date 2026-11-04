<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JenisSimpanan extends Model
{
    protected $table = 'jenis_simpanan';

    public $timestamps = false;

    protected $fillable = ['kode_jenis', 'nama_jenis', 'deskripsi', 'minimum', 'maksimum', 'bagi_hasil', 'status'];

    protected function casts(): array
    {
        return ['minimum' => 'decimal:2', 'maksimum' => 'decimal:2', 'bagi_hasil' => 'decimal:2'];
    }
}
