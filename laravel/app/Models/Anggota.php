<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Anggota extends Model
{
    protected $table = 'anggota';

    public $timestamps = false;

    protected $fillable = [
        'kode_anggota',
        'kode_anggota_jaminan',
        'kategori_shu',
        'ketua_kelompok_id',
        'nama_lengkap',
        'alamat',
        'telepon',
        'email',
        'tempat_lahir',
        'tanggal_lahir',
        'jenis_kelamin',
        'pekerjaan',
        'ktp',
        'rekening_bank',
        'user_id',
        'status',
        'nonaktif_jadwal',
        'tanggal_daftar',
        'foto_ktp',
        'foto_kk',
        'foto_ktp_pasangan',
    ];

    protected function casts(): array
    {
        return [
            'tanggal_lahir' => 'date',
            'tanggal_daftar' => 'date',
            'nonaktif_jadwal' => 'date',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function ketuaKelompok(): BelongsTo
    {
        return $this->belongsTo(self::class, 'ketua_kelompok_id');
    }

    public function pengajuanPinjaman(): HasMany
    {
        return $this->hasMany(PengajuanPinjaman::class, 'anggota_id');
    }

    public function pinjaman(): HasMany
    {
        return $this->hasMany(Pinjaman::class, 'anggota_id');
    }
}
