<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;

class CompleteDisbursementPhotosRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('disburse-loans') ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $loanId = (int) $this->route('loan');
        $needsCollateral = (bool) DB::table('pinjaman as p')
            ->join('jenis_pinjaman as jp', 'jp.id', '=', 'p.jenis_pinjaman_id')
            ->where('p.id', $loanId)
            ->value('jp.memerlukan_jaminan');

        return [
            'foto_nasabah' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'foto_barang_jaminan' => [$needsCollateral ? 'nullable' : 'prohibited', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'foto_nasabah_barang' => [$needsCollateral ? 'nullable' : 'prohibited', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ];
    }
}
