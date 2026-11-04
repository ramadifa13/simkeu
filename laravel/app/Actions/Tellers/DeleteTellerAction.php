<?php

namespace App\Actions\Tellers;

use App\Models\User;
use DomainException;
use InvalidArgumentException;

class DeleteTellerAction
{
    public function handle(User $teller): void
    {
        if ($teller->role !== 'teller') {
            throw new InvalidArgumentException('Akun bukan teller.');
        }

        if ($teller->status === 'aktif') {
            $otherActive = User::query()
                ->where('role', 'teller')
                ->where('status', 'aktif')
                ->whereKeyNot($teller->id)
                ->exists();

            if (! $otherActive) {
                throw new DomainException("Tidak dapat menghapus {$teller->nama_lengkap}. Ini teller aktif terakhir — nonaktifkan dulu atau buat teller lain agar operasional tetap jalan.");
            }
        }

        $teller->delete();
    }
}
