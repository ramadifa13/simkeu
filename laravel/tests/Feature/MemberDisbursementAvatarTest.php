<?php

namespace Tests\Feature;

use App\Actions\Members\ResolveMemberDisbursementPhoto;
use App\Models\Anggota;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MemberDisbursementAvatarTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('username');
            $table->string('password')->nullable();
            $table->string('nama_lengkap');
            $table->string('email')->nullable();
            $table->string('telepon')->nullable();
            $table->string('role');
            $table->string('status')->default('aktif');
            $table->string('foto_profil')->nullable();
            $table->string('remember_token')->nullable();
        });

        Schema::create('anggota', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('user_id')->nullable();
            $table->string('kode_anggota')->nullable();
            $table->string('nama_lengkap')->nullable();
            $table->string('status')->default('aktif');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('pinjaman', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('anggota_id');
            $table->string('foto_pencairan')->nullable();
            $table->string('status')->default('aktif');
            $table->date('tanggal_pencairan')->nullable();
        });
    }

    public function test_resolve_prefers_active_loan_then_latest_paid(): void
    {
        DB::table('pinjaman')->insert([
            ['id' => 1, 'anggota_id' => 7, 'foto_pencairan' => 'pencairan/lunas-lama.jpg', 'status' => 'lunas', 'tanggal_pencairan' => '2024-01-01'],
            ['id' => 2, 'anggota_id' => 7, 'foto_pencairan' => 'pencairan/aktif.jpg', 'status' => 'aktif', 'tanggal_pencairan' => '2025-06-01'],
            ['id' => 3, 'anggota_id' => 7, 'foto_pencairan' => 'pencairan/lunas-baru.jpg', 'status' => 'lunas', 'tanggal_pencairan' => '2025-12-01'],
        ]);

        $this->assertSame(
            'pencairan/aktif.jpg',
            app(ResolveMemberDisbursementPhoto::class)->handle(7)
        );

        DB::table('pinjaman')->where('id', 2)->update(['status' => 'lunas']);

        $this->assertSame(
            'pencairan/lunas-baru.jpg',
            app(ResolveMemberDisbursementPhoto::class)->handle(7)
        );
    }

    public function test_anggota_profile_photo_serves_disbursement_photo(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('pencairan/nasabah-uang.webp', 'fake-image');

        $user = new User([
            'username' => 'agt.foto',
            'nama_lengkap' => 'Anggota Foto',
            'email' => null,
            'telepon' => null,
            'status' => 'aktif',
            'foto_profil' => null,
        ]);
        $user->forceFill([
            'password' => Hash::make('password'),
            'role' => 'anggota',
        ])->save();

        $member = new Anggota([
            'kode_anggota' => 'A001',
            'nama_lengkap' => 'Anggota Foto',
            'status' => 'aktif',
        ]);
        $member->forceFill(['user_id' => $user->id])->save();

        DB::table('pinjaman')->insert([
            'anggota_id' => $member->id,
            'foto_pencairan' => 'pencairan/nasabah-uang.webp',
            'status' => 'aktif',
            'tanggal_pencairan' => '2025-01-15',
        ]);

        $this->actingAs($user)
            ->get(route('profile.photo'))
            ->assertOk();

        $this->actingAs($user)
            ->put(route('profile.update'), [
                'nama_lengkap' => $user->nama_lengkap,
                'foto_profil' => \Illuminate\Http\UploadedFile::fake()->image('hack.jpg'),
            ])
            ->assertSessionHasErrors('foto_profil');

        $user->refresh();
        $this->assertNull($user->foto_profil);
    }

    public function test_staff_can_view_member_disbursement_avatar(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('pencairan/nasabah.webp', 'fake-image');

        $admin = new User([
            'username' => 'admin.avatar',
            'nama_lengkap' => 'Admin Avatar',
            'email' => null,
            'telepon' => null,
            'status' => 'aktif',
        ]);
        $admin->forceFill([
            'password' => Hash::make('password'),
            'role' => 'admin',
        ])->save();

        $member = Anggota::query()->create([
            'kode_anggota' => 'A002',
            'nama_lengkap' => 'Nasabah Uang',
            'status' => 'aktif',
        ]);

        DB::table('pinjaman')->insert([
            'anggota_id' => $member->id,
            'foto_pencairan' => 'pencairan/nasabah.webp',
            'status' => 'aktif',
            'tanggal_pencairan' => '2025-02-01',
        ]);

        $this->actingAs($admin)
            ->get(route('portal.members.avatar', ['role' => 'admin', 'member' => $member->id]))
            ->assertOk();
    }
}
