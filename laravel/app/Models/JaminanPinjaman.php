<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JaminanPinjaman extends Model
{
    protected $table = 'jaminan_pinjaman';

    public $timestamps = false;

    protected $fillable = [
        'pengajuan_id',
        'pinjaman_id',
        'jenis_barang',
        'identitas_barang',
        'deskripsi_barang',
        'nilai_taksiran',
        'foto_barang',
        'foto_nasabah_barang',
        'status_jaminan',
        'tanggal_dikembalikan',
    ];

    protected function casts(): array
    {
        return [
            'nilai_taksiran' => 'decimal:2',
            'tanggal_dikembalikan' => 'date',
            'created_at' => 'datetime',
        ];
    }

    public function pengajuanPinjaman(): BelongsTo
    {
        return $this->belongsTo(PengajuanPinjaman::class, 'pengajuan_id');
    }

    public function pinjaman(): BelongsTo
    {
        return $this->belongsTo(Pinjaman::class, 'pinjaman_id');
    }
}
