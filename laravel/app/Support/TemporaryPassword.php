<?php

namespace App\Support;

use Illuminate\Support\Str;

class TemporaryPassword
{
    /** One-time plaintext for staff to convey; never store this value. */
    public static function generate(int $length = 10): string
    {
        return Str::password($length, letters: true, numbers: true, symbols: false);
    }
}
