<?php

namespace Tests\Feature;

use App\Actions\Dashboard\GetDashboardData;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Mockery\MockInterface;
use Tests\TestCase;

class PortalLayoutSmokeTest extends TestCase
{
    public function test_portal_layout_survives_corrupt_notification_cache_payload(): void
    {
        Cache::put('portal-notif:999:admin', 'not-an-array', 60);

        $this->mock(GetDashboardData::class, function (MockInterface $mock): void {
            $mock->shouldReceive('handle')->once()->andReturn([
                'roleLabel' => 'Admin',
                'intro' => 'Ringkasan aman.',
                'cards' => [],
                'alerts' => [],
                'activity' => collect(),
                'periodLabel' => 'Data pengujian.',
                'trends' => [
                    'portfolio' => [],
                    'collectibility' => [],
                    'penalties' => [],
                    'profit' => [],
                ],
            ]);
        });

        $user = new User(['id' => 999, 'nama_lengkap' => 'Pengguna Uji', 'role' => 'admin', 'status' => 'aktif']);
        $user->exists = true;

        $this->actingAs($user)
            ->get('/portal/admin/dashboard')
            ->assertOk()
            ->assertSee('Ringkasan operasional');
    }
}
