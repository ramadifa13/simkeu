<?php

namespace App\Http\Requests;

use App\Models\Anggota;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin' || $this->user()?->role === 'teller';
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'kode_anggota' => ['required', 'string', 'max:20', 'alpha_dash', Rule::unique('anggota', 'kode_anggota')->ignore($this->memberModel()), Rule::unique('users', 'username')->ignore($this->memberModel()->user_id)],
            'kode_anggota_jaminan' => ['nullable', 'string', 'max:20', 'alpha_dash', Rule::unique('anggota', 'kode_anggota_jaminan')->ignore($this->memberModel())],
            'nama_lengkap' => ['required', 'string', 'max:100'],
            'alamat' => ['nullable', 'string'],
            'telepon' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:100'],
            'tempat_lahir' => ['nullable', 'string', 'max:50'],
            'tanggal_lahir' => ['nullable', 'date', 'before_or_equal:today'],
            'jenis_kelamin' => ['nullable', Rule::in(['L', 'P'])],
            'pekerjaan' => ['nullable', 'string', 'max:50'],
            'ktp' => ['nullable', 'string', 'max:20'],
            'rekening_bank' => ['nullable', 'string', 'max:100'],
            'kategori_shu' => ['required', Rule::in(['anggota', 'ketua_kelompok'])],
            'foto_ktp' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'foto_kk' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'foto_ktp_pasangan' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ];
    }

    private function memberModel(): Anggota
    {
        return $this->route('member');
    }
}
