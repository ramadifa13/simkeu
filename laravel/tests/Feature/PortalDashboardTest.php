<?php

namespace Tests\Feature;

use Tests\TestCase;

class PortalDashboardTest extends TestCase
{
    public function test_dashboard_shell_requires_authentication(): void
    {
        $response = $this->get('/portal/admin/dashboard');

        $response->assertRedirect(route('login'));
    }
}
