<?php

namespace Tests\Feature;

use App\Actions\Dashboard\GetDashboardData;
use App\Models\User;
use Mockery\MockInterface;
use Tests\TestCase;

class DashboardRoleAccessTest extends TestCase
{
    public function test_admin_dashboard_uses_role_scoped_dashboard_data(): void
    {
        $this->mock(GetDashboardData::class, function (MockInterface $mock): void {
            $mock->shouldReceive('handle')->once()->andReturn([
                'roleLabel' => 'Admin',
                'intro' => 'Ringkasan aman.',
                'cards' => [],
                'alerts' => [],
                'activity' => collect(),
                'periodLabel' => 'Data pengujian.',
                'trends' => [
                    'portfolio' => [['label' => 'Nov', 'value' => 3, 'secondary' => 20]],
                    'collectibility' => [['label' => 'Nov', 'value' => 1, 'secondary' => 100000]],
                    'penalties' => [['label' => 'Nov', 'value' => 0, 'secondary' => 0]],
                    'profit' => [['label' => 'Nov', 'value' => 4.2, 'secondary' => 50000]],
                ],
            ]);
        });

        $response = $this->actingAs($this->user('admin'))->get('/portal/admin/dashboard');

        $response->assertOk()
            ->assertSee('Ringkasan operasional')
            ->assertSee('Ringkasan aman.')
            ->assertSee('Tren operasional')
            ->assertSee('Grafik batang Pertumbuhan NOA');
    }

    public function test_user_cannot_open_dashboard_of_another_role(): void
    {
        $response = $this->actingAs($this->user('teller'))->get('/portal/admin/dashboard');

        $response->assertForbidden();
    }

    public function test_module_placeholder_accepts_structured_breadcrumbs(): void
    {
        $response = $this->actingAs($this->user('admin'))->get('/portal/admin/modul/simpanan-kas');

        $response->assertStatus(503)->assertSee('Simpanan & kas');
    }

    public function test_dashboard_drilldown_is_a_real_authorized_data_page(): void
    {
        $response = $this->actingAs($this->user('admin'))->get('/portal/admin/dashboard/detail/savings');

        $response->assertOk()->assertSee('Filter dashboard diterapkan');
    }

    private function user(string $role): User
    {
        $user = new User(['id' => 999, 'nama_lengkap' => 'Pengguna Uji', 'role' => $role, 'status' => 'aktif']);
        $user->exists = true;

        return $user;
    }
}
