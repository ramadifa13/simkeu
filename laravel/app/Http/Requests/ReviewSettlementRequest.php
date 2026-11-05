<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewSettlementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('review-loan-applications') ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(['disetujui', 'ditolak'])],
            'diskon' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'catatan' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
