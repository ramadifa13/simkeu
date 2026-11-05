<?php

namespace App\Support;

use App\Actions\Members\ResolveMemberDisbursementPhoto;
use App\Models\Anggota;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/** Resolve sidebar/profile avatar URL for the signed-in user. */
class PortalAvatar
{
    /**
     * @return array{url:?string,version:?string,source:?string}
     */
    public function forUser(?User $user): array
    {
        if (! $user) {
            return ['url' => null, 'version' => null, 'source' => null];
        }

        if ($user->role === 'anggota') {
            $memberId = Anggota::query()->where('user_id', $user->id)->value('id');
            $path = app(ResolveMemberDisbursementPhoto::class)->handle($memberId ? (int) $memberId : null);
            if ($path && Storage::disk('local')->exists($path)) {
                return [
                    'url' => route('profile.photo'),
                    'version' => md5($path),
                    'source' => 'disbursement',
                ];
            }

            return ['url' => null, 'version' => null, 'source' => null];
        }

        if (filled($user->foto_profil) && Storage::disk('local')->exists((string) $user->foto_profil)) {
            return [
                'url' => route('profile.photo'),
                'version' => md5((string) $user->foto_profil),
                'source' => 'profile',
            ];
        }

        return ['url' => null, 'version' => null, 'source' => null];
    }
}
