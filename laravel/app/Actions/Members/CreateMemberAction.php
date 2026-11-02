<?php

namespace App\Actions\Members;

use App\Models\Anggota;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class CreateMemberAction
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(array $attributes): Anggota
    {
        return DB::transaction(function () use ($attributes): Anggota {
            $username = (string) $attributes['kode_anggota'];

            $user = User::query()->create([
                'username' => $username,
                'password' => Hash::make('123456'),
                'nama_lengkap' => $attributes['nama_lengkap'],
                'email' => $attributes['email'] ?? null,
                'telepon' => $attributes['telepon'] ?? null,
                'role' => 'anggota',
                'status' => 'aktif',
            ]);

            $member = Anggota::query()->create([
                ...Arr::except($attributes, ['foto_ktp', 'foto_kk', 'foto_ktp_pasangan', 'kode_anggota_jaminan']),
                'kode_anggota_jaminan' => $attributes['kode_anggota_jaminan'] ?? null,
                'user_id' => $user->id,
                'status' => 'aktif',
                'tanggal_daftar' => now()->toDateString(),
            ]);

            foreach (['foto_ktp', 'foto_kk', 'foto_ktp_pasangan'] as $documentField) {
                /** @var UploadedFile $document */
                $document = $attributes[$documentField];
                $member->{$documentField} = $document->store("anggota/{$member->id}", 'local');
            }

            $member->save();

            return $member;
        });
    }
}
