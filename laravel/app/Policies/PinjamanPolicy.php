<?php

namespace App\Policies;

use App\Models\Anggota;
use App\Models\Pinjaman;
use App\Models\User;

class PinjamanPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, ['admin', 'teller', 'anggota'], true);
    }

    public function view(User $user, Pinjaman $loan): bool
    {
        if (in_array($user->role, ['admin', 'teller'], true)) {
            return true;
        }

        if ($user->role !== 'anggota') {
            return false;
        }

        $memberId = Anggota::query()->where('user_id', $user->id)->value('id');

        return $memberId !== null && (int) $loan->anggota_id === (int) $memberId;
    }

    public function collect(User $user): bool
    {
        return in_array($user->role, ['admin', 'teller'], true);
    }

    public function settle(User $user): bool
    {
        return $this->collect($user);
    }

    public function reversePayment(User $user): bool
    {
        return $user->role === 'admin';
    }
}
