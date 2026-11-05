<?php

namespace App\Actions\Members;

use App\Models\Anggota;
use App\Models\User;
use App\Support\ImageStorage;
use App\Support\TemporaryPassword;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class CreateMemberAction
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return array{member: Anggota, password: string}
     */
    public function handle(array $attributes, ?GenerateMemberCodes $codes = null): array
    {
        $codes ??= app(GenerateMemberCodes::class);

        return DB::transaction(function () use ($attributes, $codes): array {
            $generatedCodes = $codes->handle($attributes['kategori_anggota'] ?? 'biasa');
            $attributes = [...$attributes, ...$generatedCodes];
            $username = $generatedCodes['kode_anggota'];
            $plainPassword = TemporaryPassword::generate();

            $user = User::query()->create([
                'username' => $username,
                'nama_lengkap' => $attributes['nama_lengkap'],
                'email' => null,
                'telepon' => $attributes['telepon'] ?? null,
                'status' => 'aktif',
            ]);
            $user->forceFill([
                'password' => Hash::make($plainPassword),
                'role' => 'anggota',
            ])->save();

            $member = Anggota::query()->create([
                ...Arr::except($attributes, ['foto_ktp', 'foto_kk', 'foto_ktp_pasangan', 'kategori_anggota', 'email']),
                'user_id' => $user->id,
                'email' => '',
                'status' => 'aktif',
                'tanggal_daftar' => $attributes['tanggal_daftar'] ?? now()->toDateString(),
            ]);

            foreach (['foto_ktp', 'foto_kk', 'foto_ktp_pasangan'] as $documentField) {
                if (! isset($attributes[$documentField])) {
                    continue;
                }

                /** @var UploadedFile $document */
                $document = $attributes[$documentField];
                $member->{$documentField} = app(ImageStorage::class)->store($document, "anggota/{$member->id}");
            }

            $member->save();

            return ['member' => $member->fresh(['user']), 'password' => $plainPassword];
        });
    }
}
