<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function makeUser(string $role, array $attributes = [], bool $exists = false): User
    {
        $user = new User($attributes);
        $user->forceFill(['role' => $role]);

        if (isset($attributes['id']) || $exists) {
            $user->exists = true;
        }

        return $user;
    }
}
