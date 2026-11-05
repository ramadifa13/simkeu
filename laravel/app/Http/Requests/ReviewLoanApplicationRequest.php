<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewLoanApplicationRequest extends FormRequest
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
            'catatan_admin' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
