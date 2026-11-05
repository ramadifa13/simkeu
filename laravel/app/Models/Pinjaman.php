<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Pinjaman extends Model
{
    protected $table = 'pinjaman';

    public $timestamps = false;

    protected $fillable = [
        'pengajuan_id',
        'kode_pinjaman',
        'anggota_id',
        'jenis_pinjaman_id',
        'jumlah_pinjaman',
        'sisa_pokok',
        'tenor',
        'bunga',
        'skema_bunga',
        'frekuensi',
        'jumlah_cicilan',
        'total_pinjaman',
        'angsuran_per_bulan',
        'tanggal_pencairan',
        'jatuh_tempo',
        'foto_pencairan',
        'dicairkan_oleh',
        'tanggal_lunas',
        'status',
        'input_mundur',
        'cicilan_dimuka',
        'kategori_shu',
        'ketua_kelompok_id',
    ];

    protected function casts(): array
    {
        return [
            'jumlah_pinjaman' => 'decimal:2',
            'sisa_pokok' => 'decimal:2',
            'bunga' => 'decimal:2',
            'total_pinjaman' => 'decimal:2',
            'angsuran_per_bulan' => 'decimal:2',
            'tanggal_pencairan' => 'date',
            'jatuh_tempo' => 'date',
            'tanggal_lunas' => 'date',
            'input_mundur' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function anggota(): BelongsTo
    {
        return $this->belongsTo(Anggota::class, 'anggota_id');
    }

    public function jenisPinjaman(): BelongsTo
    {
        return $this->belongsTo(JenisPinjaman::class, 'jenis_pinjaman_id');
    }

    public function pengajuanPinjaman(): BelongsTo
    {
        return $this->belongsTo(PengajuanPinjaman::class, 'pengajuan_id');
    }

    public function dicairkanOleh(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dicairkan_oleh');
    }

    public function ketuaKelompok(): BelongsTo
    {
        return $this->belongsTo(Anggota::class, 'ketua_kelompok_id');
    }

    public function angsuran(): HasMany
    {
        return $this->hasMany(Angsuran::class, 'pinjaman_id');
    }

    public function jaminanPinjaman(): HasOne
    {
        return $this->hasOne(JaminanPinjaman::class, 'pinjaman_id');
    }
}
