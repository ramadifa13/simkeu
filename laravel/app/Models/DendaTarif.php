<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DendaTarif extends Model
{
    protected $table = 'denda_tarif';

    public $timestamps = false;

    protected $fillable = ['jumlah_pinjaman', 'denda_per_hari'];

    protected function casts(): array
    {
        return ['jumlah_pinjaman' => 'decimal:2', 'denda_per_hari' => 'decimal:2'];
    }
}
