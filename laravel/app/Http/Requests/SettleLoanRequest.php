<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class SettleLoanRequest extends FormRequest
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
        $loanId = (int) $this->route('loan');
        $needsCollateral = (bool) DB::table('pinjaman as p')
            ->join('jenis_pinjaman as jp', 'jp.id', '=', 'p.jenis_pinjaman_id')
            ->where('p.id', $loanId)
            ->value('jp.memerlukan_jaminan');

        $earlyApproved = false;
        if (Schema::hasTable('layanan_pelunasan')) {
            $earlyApproved = DB::table('layanan_pelunasan')
                ->where('pinjaman_id', $loanId)
                ->where('status', 'disetujui')
                ->exists();
        }

        $takeCollateral = $this->boolean('ambil_jaminan_langsung') && ! $earlyApproved;
        $requiresPhysicalCheck = $earlyApproved && $needsCollateral;

        return [
            'tanggal_bayar' => ['required', 'date', 'before_or_equal:today'],
            'metode' => ['required', Rule::in(['cash', 'transfer'])],
            'catatan' => ['nullable', 'string', 'max:1000'],
            'bukti' => [$earlyApproved ? 'required' : 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'cek_fisik' => [$requiresPhysicalCheck ? 'accepted' : 'nullable'],
            'ambil_jaminan_langsung' => ['nullable', 'boolean'],
            'cek_jaminan_nasabah' => [$takeCollateral ? 'accepted' : 'nullable'],
            'foto_serah_terima_jaminan' => [$takeCollateral ? 'required' : 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'ttd_jaminan_nasabah' => [$takeCollateral ? 'required' : 'nullable', 'image', 'mimes:webp', 'max:1024'],
            'ttd_jaminan_teller' => [$takeCollateral ? 'required' : 'nullable', 'image', 'mimes:webp', 'max:1024'],
            'mode_cetak_akad' => [$takeCollateral ? 'required' : 'nullable', Rule::in(['otomatis', 'manual'])],
        ];
    }
}
