<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JenisPinjamanTarif extends Model
{
    protected $table = 'jenis_pinjaman_tarif';

    public $timestamps = false;

    protected $fillable = ['jenis_pinjaman_id', 'tenor_bulan', 'bunga'];

    protected function casts(): array
    {
        return ['bunga' => 'decimal:2'];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(JenisPinjaman::class, 'jenis_pinjaman_id');
    }
}
