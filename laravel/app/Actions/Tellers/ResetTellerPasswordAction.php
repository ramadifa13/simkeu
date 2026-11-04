<?php

namespace App\Actions\Tellers;

use App\Models\User;
use App\Support\TemporaryPassword;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;

class ResetTellerPasswordAction
{
    /** @return array{teller: User, password: string} */
    public function handle(User $teller): array
    {
        if ($teller->role !== 'teller') {
            throw new InvalidArgumentException('Akun bukan teller.');
        }

        $plainPassword = TemporaryPassword::generate();
        $teller->forceFill([
            'password' => Hash::make($plainPassword),
        ])->save();

        return ['teller' => $teller->fresh(), 'password' => $plainPassword];
    }
}
