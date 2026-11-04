<?php

namespace App\Actions\Members;

use App\Models\Anggota;
use App\Models\User;
use App\Support\TemporaryPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class ResetMemberPasswordAction
{
    /** @return array{member: Anggota, password: string} */
    public function handle(Anggota $member): array
    {
        return DB::transaction(function () use ($member): array {
            $member->loadMissing('user');
            $user = $member->user;
            $plainPassword = TemporaryPassword::generate();

            if (! $user) {
                $user = User::query()->create([
                    'username' => $member->kode_anggota,
                    'nama_lengkap' => $member->nama_lengkap,
                    'email' => $member->email,
                    'telepon' => $member->telepon,
                    'status' => $member->status,
                ]);
                $user->forceFill([
                    'password' => Hash::make($plainPassword),
                    'role' => 'anggota',
                ])->save();
                $member->update(['user_id' => $user->id]);
            } else {
                $user->fill([
                    'username' => $member->kode_anggota,
                    'nama_lengkap' => $member->nama_lengkap,
                    'email' => $member->email,
                    'telepon' => $member->telepon,
                    'status' => $member->status,
                ]);
                $user->forceFill(['password' => Hash::make($plainPassword)])->save();
            }

            return ['member' => $member->fresh(['user']), 'password' => $plainPassword];
        });
    }
}
