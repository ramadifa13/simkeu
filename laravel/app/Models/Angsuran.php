<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Angsuran extends Model
{
    protected $table = 'angsuran';

    public $timestamps = false;

    protected $fillable = [
        'pinjaman_id',
        'angsuran_ke',
        'jumlah_angsuran',
        'pokok',
        'bunga',
        'denda',
        'denda_manual',
        'tanggal_jatuh_tempo',
        'tanggal_bayar',
        'status',
        'cara_bayar',
        'keterlambatan_hari',
    ];

    protected function casts(): array
    {
        return [
            'jumlah_angsuran' => 'decimal:2',
            'pokok' => 'decimal:2',
            'bunga' => 'decimal:2',
            'denda' => 'decimal:2',
            'denda_manual' => 'boolean',
            'tanggal_jatuh_tempo' => 'date',
            'tanggal_bayar' => 'date',
            'created_at' => 'datetime',
        ];
    }

    public function pinjaman(): BelongsTo
    {
        return $this->belongsTo(Pinjaman::class, 'pinjaman_id');
    }
}
