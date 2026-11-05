<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSavingsTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-savings') ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'anggota_id' => ['required', 'integer', Rule::exists('anggota', 'id')],
            'jenis_simpanan_id' => ['required', 'integer', Rule::exists('jenis_simpanan', 'id')],
            'jumlah' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'keterangan' => ['nullable', 'string', 'max:255'],
        ];
    }
}
