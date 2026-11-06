<?php

namespace App\Http\Requests;

use App\Support\ImageLimits;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLoanApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-loan-applications') ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'anggota_id' => ['required', 'integer'],
            'jenis_pinjaman_id' => ['required', 'integer'],
            'jumlah_pinjaman' => ['required', 'numeric', 'gt:0'],
            'tenor' => ['required', 'integer', 'min:1', 'max:60'],
            'frekuensi' => ['required', Rule::in(['bulanan', 'mingguan', 'dua_mingguan'])],
            'hari_angsuran' => ['nullable', 'integer', 'between:1,7'],
            'tanggal_pengajuan' => ['required', 'date', 'before_or_equal:today'],
            'tujuan_pinjaman' => ['required', 'string', 'max:2000'],
            'fasilitas_prioritas' => ['nullable', 'boolean'],
            'kategori_shu' => ['nullable', Rule::in(['anggota', 'ketua_kelompok'])],
            'ketua_kelompok_id' => ['nullable', 'integer'],
            'kategori_jaminan' => ['nullable', Rule::in(['hp', 'sepeda_motor', 'emas'])],
            'hp_jenis' => ['nullable', 'string', 'max:100'],
            'hp_merk_tipe' => ['nullable', 'string', 'max:100'],
            'hp_warna' => ['nullable', 'string', 'max:100'],
            'hp_imei' => ['nullable', 'string', 'max:100'],
            'hp_nomor_seri' => ['nullable', 'string', 'max:100'],
            'hp_nomor_model' => ['nullable', 'string', 'max:100'],
            'hp_kelengkapan' => ['nullable', 'string', 'max:255'],
            'hp_kondisi' => ['nullable', 'string', 'max:255'],
            'motor_jenis_kendaraan' => ['nullable', 'string', 'max:100'],
            'motor_merk_tipe' => ['nullable', 'string', 'max:100'],
            'motor_tahun' => ['nullable', 'string', 'max:20'],
            'motor_nopol' => ['nullable', 'string', 'max:50'],
            'motor_no_rangka' => ['nullable', 'string', 'max:100'],
            'motor_no_mesin' => ['nullable', 'string', 'max:100'],
            'motor_warna' => ['nullable', 'string', 'max:50'],
            'motor_atas_nama_bpkb' => ['nullable', 'string', 'max:100'],
            'motor_no_bpkb' => ['nullable', 'string', 'max:100'],
            'motor_kondisi' => ['nullable', 'string', 'max:255'],
            'emas_jenis_barang' => ['nullable', 'string', 'max:100'],
            'emas_berat' => ['nullable', 'string', 'max:50'],
            'emas_kadar' => ['nullable', 'string', 'max:50'],
            'emas_bentuk_ciri' => ['nullable', 'string', 'max:255'],
            'emas_surat_nota' => ['nullable', 'string', 'max:255'],
            'foto_kk' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.ImageLimits::SOURCE_MAX_KILOBYTES],
            'foto_ktp_pasangan' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.ImageLimits::SOURCE_MAX_KILOBYTES],
            'jaminan' => ['nullable', 'array'],
            'jaminan.jenis_barang' => ['nullable', 'string', 'max:100'],
            'jaminan.identitas_barang' => ['nullable', 'string', 'max:100'],
            'jaminan.deskripsi_barang' => ['nullable', 'string', 'max:5000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'fasilitas_prioritas' => $this->boolean('fasilitas_prioritas'),
        ]);
    }
}
