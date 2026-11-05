<?php

namespace App\Actions\Notifications;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class BustPortalNotificationCache
{
    public const KEY = 'portal-notif-bust';

    public function handle(): void
    {
        Cache::forever(self::KEY, (string) Str::uuid());
    }

    public function token(): string
    {
        $token = Cache::get(self::KEY);
        if (! is_string($token) || $token === '') {
            $token = (string) Str::uuid();
            Cache::forever(self::KEY, $token);
        }

        return $token;
    }
}
