<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RequestInstallmentReversalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('request-installment-reversal') ?? false;
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
