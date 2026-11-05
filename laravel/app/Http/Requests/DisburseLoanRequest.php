<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;

class DisburseLoanRequest extends FormRequest
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
        $applicationId = (int) $this->route('application');
        $needsCollateral = (bool) DB::table('pengajuan_pinjaman as pp')
            ->join('jenis_pinjaman as jp', 'jp.id', '=', 'pp.jenis_pinjaman_id')
            ->where('pp.id', $applicationId)
            ->value('jp.memerlukan_jaminan');

        return [
            'tanggal_pencairan' => ['required', 'date', 'before_or_equal:today'],
            'sudah_dibayar' => ['nullable', 'integer', 'min:0'],
            'foto_nasabah' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'foto_barang_jaminan' => [$needsCollateral ? 'required' : 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'foto_nasabah_barang' => [$needsCollateral ? 'required' : 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'nama_saksi_1' => ['required', 'string', 'max:100'],
            'nama_saksi_2' => ['required', 'string', 'max:100'],
            'ttd_nasabah' => ['required', 'image', 'mimes:webp,png', 'max:1024'],
            'ttd_teller' => ['required', 'image', 'mimes:webp,png', 'max:1024'],
            'ttd_saksi_1' => ['required', 'image', 'mimes:webp,png', 'max:1024'],
            'ttd_saksi_2' => ['required', 'image', 'mimes:webp,png', 'max:1024'],
            'lampiran' => ['nullable', 'array', 'max:10'],
            'lampiran.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ];
    }
}
