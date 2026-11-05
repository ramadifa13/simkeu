<?php

namespace Tests\Unit;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class PhaseEightAuthorizationTest extends TestCase
{
    public function test_admin_can_manage_phase_eight_modules(): void
    {
        $admin = $this->makeUser('admin');
        $this->assertTrue(Gate::forUser($admin)->allows('manage-layanan'));
        $this->assertTrue(Gate::forUser($admin)->allows('decide-layanan'));
        $this->assertTrue(Gate::forUser($admin)->allows('manage-shu'));
        $this->assertTrue(Gate::forUser($admin)->allows('view-own-shu'));
        $this->assertTrue(Gate::forUser($admin)->allows('manage-migration'));
        $this->assertTrue(Gate::forUser($admin)->allows('run-backup'));
    }

    public function test_teller_can_manage_layanan_but_not_admin_only_modules(): void
    {
        $teller = $this->makeUser('teller');
        $this->assertTrue(Gate::forUser($teller)->allows('manage-layanan'));
        $this->assertFalse(Gate::forUser($teller)->allows('decide-layanan'));
        $this->assertFalse(Gate::forUser($teller)->allows('manage-shu'));
        $this->assertFalse(Gate::forUser($teller)->allows('manage-migration'));
        $this->assertFalse(Gate::forUser($teller)->allows('run-backup'));
    }

    public function test_member_can_view_own_shu_only(): void
    {
        $member = $this->makeUser('anggota');
        $this->assertFalse(Gate::forUser($member)->allows('manage-layanan'));
        $this->assertTrue(Gate::forUser($member)->allows('view-own-shu'));
        $this->assertFalse(Gate::forUser($member)->allows('manage-shu'));
        $this->assertFalse(Gate::forUser($member)->allows('run-backup'));
    }
}
