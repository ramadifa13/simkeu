<?php

namespace Tests\Feature;

use Tests\TestCase;

class PhaseThreeNavigationTest extends TestCase
{
    public function test_landing_exposes_public_navigation_and_service_content(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('Masuk ke portal');
        $response->assertSee('Pertanyaan umum');
        $response->assertSee('Lewati ke konten utama');
    }

    public function test_unauthenticated_visitors_are_redirected_from_portal_modules(): void
    {
        $response = $this->get('/portal/admin/modul/pinjaman');

        $response->assertRedirect(route('login'));
    }
}
