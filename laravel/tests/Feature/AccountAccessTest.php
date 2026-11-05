<?php

namespace Tests\Feature;

use Tests\TestCase;

class AccountAccessTest extends TestCase
{
    public function test_public_password_reset_page_is_not_available(): void
    {
        // Intentional: parity with native — only Admin/Teller may reset passwords.
        $response = $this->get('/lupa-kata-sandi');

        $response->assertNotFound();
    }

    public function test_profile_requires_authentication(): void
    {
        $response = $this->get(route('profile.edit'));

        $response->assertRedirect(route('login'));
    }
}
