<?php

namespace Tests\Unit;

use App\Models\Anggota;
use App\Models\User;
use App\Policies\AnggotaPolicy;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class PhaseFiveAuthorizationTest extends TestCase
{
    public function test_admin_can_manage_phase_five_master_and_configuration(): void
    {
        $admin = $this->user('admin');

        $this->assertTrue(Gate::forUser($admin)->allows('manage-master-data'));
        $this->assertTrue(Gate::forUser($admin)->allows('manage-tellers'));
        $this->assertTrue(Gate::forUser($admin)->allows('manage-cooperative-settings'));
        $this->assertTrue(Gate::forUser($admin)->allows('view-audit-log'));
    }

    public function test_teller_cannot_manage_admin_only_phase_five_menus(): void
    {
        $teller = $this->user('teller');

        $this->assertFalse(Gate::forUser($teller)->allows('manage-master-data'));
        $this->assertFalse(Gate::forUser($teller)->allows('manage-tellers'));
        $this->assertFalse(Gate::forUser($teller)->allows('manage-cooperative-settings'));
        $this->assertFalse(Gate::forUser($teller)->allows('view-audit-log'));
    }

    public function test_member_policy_allows_staff_to_export_but_never_members(): void
    {
        $policy = new AnggotaPolicy;
        $member = new Anggota;

        $this->assertTrue($policy->export($this->user('admin')));
        $this->assertTrue($policy->export($this->user('teller')));
        $this->assertFalse($policy->export($this->user('anggota')));
        $this->assertFalse($policy->view($this->user('anggota'), $member));
    }

    private function user(string $role): User
    {
        return new User(['id' => 900, 'nama_lengkap' => 'Pengguna Uji', 'role' => $role, 'status' => 'aktif']);
    }
}
