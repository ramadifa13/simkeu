<?php

namespace App\Actions\Loans;

use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class CheckoutInstallmentCartAction
{
    public function __construct(private readonly PayInstallmentAction $pay) {}

    /**
     * @param  list<array{installment_id:int,tanggal_bayar:string,metode:string,denda?:float|null}>  $items
     * @return list<array{loan_id:int,installment_id:int,cash_id:int,total:float,lunas:bool}>
     */
    public function handle(array $items, User $actor): array
    {
        if ($items === []) {
            throw new DomainException('Keranjang angsuran kosong.');
        }

        return DB::transaction(function () use ($items, $actor): array {
            $results = [];
            foreach ($items as $item) {
                $id = (int) ($item['installment_id'] ?? 0);
                $date = (string) ($item['tanggal_bayar'] ?? '');
                $method = (string) ($item['metode'] ?? '');
                $penalty = array_key_exists('denda', $item) && $item['denda'] !== null && $item['denda'] !== ''
                    ? (float) $item['denda']
                    : null;

                if ($id <= 0 || $date === '' || $method === '') {
                    throw new DomainException('Data pembayaran keranjang tidak lengkap.');
                }

                $results[] = $this->pay->handle($id, $date, $method, $actor, $penalty);
            }

            return $results;
        }, 3);
    }
}
