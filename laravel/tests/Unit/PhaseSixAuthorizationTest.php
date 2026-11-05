<?php

namespace Tests\Unit;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class PhaseSixAuthorizationTest extends TestCase
{
    public function test_admin_can_access_financial_controls_and_reports(): void
    {
        $admin = $this->user('admin');

        $this->assertTrue(Gate::forUser($admin)->allows('manage-savings'));
        $this->assertTrue(Gate::forUser($admin)->allows('manage-waserda'));
        $this->assertTrue(Gate::forUser($admin)->allows('delete-waserda'));
        $this->assertTrue(Gate::forUser($admin)->allows('manage-savings-corrections'));
        $this->assertTrue(Gate::forUser($admin)->allows('reconcile-cash'));
        $this->assertTrue(Gate::forUser($admin)->allows('view-operational-reports'));
        $this->assertTrue(Gate::forUser($admin)->allows('view-admin-reports'));
        $this->assertTrue(Gate::forUser($admin)->allows('view-financial-parity'));
    }

    public function test_teller_can_operate_but_cannot_change_or_export_financial_ledger(): void
    {
        $teller = $this->user('teller');

        $this->assertTrue(Gate::forUser($teller)->allows('manage-savings'));
        $this->assertTrue(Gate::forUser($teller)->allows('manage-waserda'));
        $this->assertFalse(Gate::forUser($teller)->allows('delete-waserda'));
        $this->assertFalse(Gate::forUser($teller)->allows('manage-savings-corrections'));
        $this->assertFalse(Gate::forUser($teller)->allows('reconcile-cash'));
        $this->assertTrue(Gate::forUser($teller)->allows('view-operational-reports'));
        $this->assertFalse(Gate::forUser($teller)->allows('view-admin-reports'));
        $this->assertFalse(Gate::forUser($teller)->allows('view-financial-parity'));
    }

    private function user(string $role): User
    {
        return $this->makeUser($role, ['id' => 901, 'nama_lengkap' => 'Pengguna Uji', 'status' => 'aktif']);
    }
}
