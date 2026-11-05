<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShuPeriode extends Model
{
    protected $table = 'shu_periode';

    public $timestamps = false;

    protected $fillable = [
        'tahun',
        'nama_periode',
        'tanggal_mulai',
        'tanggal_selesai',
        'status',
        'total_shu',
        'kebijakan_json',
        'alokasi_json',
        'snapshot_hash',
        'referensi_rat',
        'dibuat_oleh',
        'dihitung_at',
        'disahkan_oleh',
        'disahkan_at',
    ];

    protected function casts(): array
    {
        return [
            'total_shu' => 'decimal:0',
            'tanggal_mulai' => 'date',
            'tanggal_selesai' => 'date',
            'dihitung_at' => 'datetime',
            'disahkan_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function pembuat(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dibuat_oleh');
    }

    public function pengesah(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disahkan_oleh');
    }
}
