<?php

namespace App\Policies;

use App\Models\Anggota;
use App\Models\User;

class AnggotaPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, ['admin', 'teller'], true);
    }

    public function view(User $user, Anggota $member): bool
    {
        return in_array($user->role, ['admin', 'teller'], true);
    }

    public function create(User $user): bool
    {
        return in_array($user->role, ['admin', 'teller'], true);
    }

    public function update(User $user, Anggota $member): bool
    {
        return in_array($user->role, ['admin', 'teller'], true);
    }

    public function viewDocument(User $user, Anggota $member): bool
    {
        return $this->view($user, $member);
    }
}
