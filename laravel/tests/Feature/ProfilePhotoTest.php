<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfilePhotoTest extends TestCase
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
    }

    public function test_user_can_upload_and_view_profile_photo(): void
    {
        Storage::fake('local');

        $user = new User([
            'username' => 'teller.foto',
            'nama_lengkap' => 'Teller Foto',
            'email' => 'teller@example.com',
            'telepon' => '08123456789',
            'status' => 'aktif',
            'foto_profil' => null,
        ]);
        $user->forceFill([
            'password' => Hash::make('password'),
            'role' => 'teller',
        ])->save();

        $path = sys_get_temp_dir().'/simkeu-avatar-'.uniqid('', true).'.jpg';
        $image = imagecreatetruecolor(120, 120);
        imagejpeg($image, $path, 90);
        imagedestroy($image);
        $file = new UploadedFile($path, 'avatar.jpg', 'image/jpeg', null, true);

        $this->actingAs($user)
            ->put(route('profile.update'), [
                'nama_lengkap' => $user->nama_lengkap,
                'email' => $user->email,
                'telepon' => $user->telepon,
                'foto_profil' => $file,
            ])
            ->assertRedirect(route('profile.edit'));

        @unlink($path);

        $user->refresh();
        $this->assertNotEmpty($user->foto_profil);
        $this->assertTrue(str_starts_with((string) $user->foto_profil, 'users/'.$user->id.'/'));
        Storage::disk('local')->assertExists($user->foto_profil);

        $this->actingAs($user)
            ->get(route('profile.photo'))
            ->assertOk();
    }
}
