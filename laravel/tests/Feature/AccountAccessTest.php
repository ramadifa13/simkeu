<?php

namespace Tests\Feature;

use Tests\TestCase;

class AccountAccessTest extends TestCase
{
    public function test_password_reset_request_page_is_available_to_guests(): void
    {
        $response = $this->get(route('password.request'));

        $response->assertOk();
        $response->assertSee('Atur ulang kata sandi');
    }

    public function test_profile_requires_authentication(): void
    {
        $response = $this->get(route('profile.edit'));

        $response->assertRedirect(route('login'));
    }
}
