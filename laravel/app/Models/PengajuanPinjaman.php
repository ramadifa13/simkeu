<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PengajuanPinjaman extends Model
{
    protected $table = 'pengajuan_pinjaman';

    public $timestamps = false;

    protected $fillable = [
        'kode_pengajuan',
        'anggota_id',
        'jenis_pinjaman_id',
        'jumlah_pinjaman',
        'tenor',
        'bunga',
        'skema_bunga',
        'frekuensi',
        'tujuan_pinjaman',
        'status',
        'catatan_admin',
        'admin_id',
        'user_id',
        'tanggal_pengajuan',
        'tanggal_persetujuan',
        'hari_angsuran',
        'fasilitas_prioritas',
        'kategori_shu',
        'ketua_kelompok_id',
    ];

    protected function casts(): array
    {
        return [
            'jumlah_pinjaman' => 'decimal:2',
            'bunga' => 'decimal:2',
            'fasilitas_prioritas' => 'boolean',
            'tanggal_pengajuan' => 'datetime',
            'tanggal_persetujuan' => 'datetime',
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

    public function pengaju(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function ketuaKelompok(): BelongsTo
    {
        return $this->belongsTo(Anggota::class, 'ketua_kelompok_id');
    }

    public function jaminanPinjaman(): HasOne
    {
        return $this->hasOne(JaminanPinjaman::class, 'pengajuan_id');
    }

    public function jaminanList(): HasMany
    {
        return $this->hasMany(JaminanPinjaman::class, 'pengajuan_id');
    }

    public function pinjaman(): HasOne
    {
        return $this->hasOne(Pinjaman::class, 'pengajuan_id');
    }
}
