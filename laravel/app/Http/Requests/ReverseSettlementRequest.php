<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReverseSettlementRequest extends FormRequest
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
            'alasan' => ['required', 'string', 'min:5', 'max:250'],
        ];
    }
}
