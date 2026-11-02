<?php

namespace Tests\Feature;

use Tests\TestCase;

class LandingPageTest extends TestCase
{
    public function test_landing_page_is_available(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('SIMKEU');
        $response->assertSee('Masuk ke portal');
    }
}
