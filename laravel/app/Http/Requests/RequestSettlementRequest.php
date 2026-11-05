<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RequestSettlementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('collect-installments') ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'catatan' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
