<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewInstallmentReversalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('decide-installment-reversal') ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(['disetujui', 'ditolak'])],
            'catatan' => ['nullable', 'string', 'max:500'],
        ];
    }
}
