<?php

namespace App\Http\Requests;

use App\Support\ImageLimits;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'nama_lengkap' => ['required', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:100'],
            'telepon' => ['nullable', 'string', 'max:30'],
            'foto_profil' => [
                Rule::prohibitedIf(fn () => $this->user()?->role === 'anggota'),
                'nullable',
                'file',
                'mimes:jpg,jpeg,png,webp',
                'max:'.ImageLimits::SOURCE_MAX_KILOBYTES,
            ],
            'hapus_foto_profil' => [
                Rule::prohibitedIf(fn () => $this->user()?->role === 'anggota'),
                'nullable',
                'boolean',
            ],
        ];
    }
}
