<?php

namespace Tests\Unit;

use App\Models\Anggota;
use App\Models\Pinjaman;
use App\Models\User;
use App\Policies\PinjamanPolicy;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PinjamanPolicyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('username');
            $table->string('password')->nullable();
            $table->string('nama_lengkap');
            $table->string('role');
            $table->string('status')->default('aktif');
        });
        Schema::create('anggota', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('user_id')->nullable();
            $table->string('kode_anggota')->nullable();
            $table->string('nama_lengkap')->nullable();
            $table->string('status')->default('aktif');
        });
        Schema::create('pinjaman', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('anggota_id');
            $table->string('status')->default('aktif');
        });
    }

    public function test_member_can_only_view_own_loan(): void
    {
        $memberUser = new User([
            'username' => 'agt1',
            'nama_lengkap' => 'Anggota Satu',
            'status' => 'aktif',
        ]);
        $memberUser->forceFill(['password' => 'x', 'role' => 'anggota'])->save();
        $member = new Anggota(['kode_anggota' => 'AGT-1', 'nama_lengkap' => 'Anggota Satu', 'status' => 'aktif']);
        $member->forceFill(['id' => 5, 'user_id' => $memberUser->id])->save();

        $own = new Pinjaman(['anggota_id' => 5]);
        $own->id = 1;
        $other = new Pinjaman(['anggota_id' => 9]);
        $other->id = 2;

        $policy = new PinjamanPolicy;
        $this->assertTrue($policy->view($memberUser, $own));
        $this->assertFalse($policy->view($memberUser, $other));
        $this->assertFalse($policy->collect($memberUser));
    }

    public function test_staff_can_collect_and_view_any_loan(): void
    {
        $teller = new User([
            'username' => 'teller1',
            'nama_lengkap' => 'Teller',
            'status' => 'aktif',
        ]);
        $teller->forceFill(['password' => 'x', 'role' => 'teller'])->save();
        $loan = new Pinjaman(['anggota_id' => 1]);
        $loan->id = 3;

        $policy = new PinjamanPolicy;
        $this->assertTrue($policy->view($teller, $loan));
        $this->assertTrue($policy->collect($teller));
        $this->assertFalse($policy->reversePayment($teller));
    }
}
