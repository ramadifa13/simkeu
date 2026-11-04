<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WaserdaTransaction extends Model
{
    protected $table = 'waserda_transaksi';

    public $timestamps = false;

    protected $fillable = ['kode_transaksi', 'anggota_id', 'items', 'total_bayar', 'metode_bayar', 'user_id'];

    protected function casts(): array
    {
        return ['items' => 'array', 'total_bayar' => 'decimal:2', 'created_at' => 'datetime'];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Anggota::class, 'anggota_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
