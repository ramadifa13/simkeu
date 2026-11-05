<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PayLoanPenaltyRequest extends FormRequest
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
            'tanggal_bayar' => ['required', 'date', 'before_or_equal:today'],
            'metode' => ['required', Rule::in(['cash', 'transfer'])],
            'jumlah' => ['required', 'numeric', 'gt:0'],
            'bukti' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ];
    }
}
