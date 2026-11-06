<?php

namespace App\Http\Requests;

use App\Support\ImageLimits;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PayInstallmentRequest extends FormRequest
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
            'denda' => ['nullable', 'numeric', 'min:0'],
            'bukti' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.ImageLimits::SOURCE_MAX_KILOBYTES],
        ];
    }
}
