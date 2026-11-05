<?php

namespace App\Http\Controllers\Account;

use App\Actions\Members\ResolveMemberDisbursementPhoto;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdatePasswordRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Models\Anggota;
use App\Support\ImageStorage;
use App\Support\PortalAvatar;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProfileController extends Controller
{
    public function edit(PortalAvatar $avatars, ResolveMemberDisbursementPhoto $disbursementPhotos): View
    {
        $user = auth()->user();
        $role = $user->role;
        $memberPhoto = null;
        if ($role === 'anggota') {
            $memberId = Anggota::query()->where('user_id', $user->id)->value('id');
            $memberPhoto = $disbursementPhotos->handle($memberId ? (int) $memberId : null);
        }

        return view('account.profile', [
            'role' => $role,
            'avatar' => $avatars->forUser($user),
            'memberDisbursementPhoto' => $memberPhoto,
        ]);
    }

    public function update(UpdateProfileRequest $request, ImageStorage $images): RedirectResponse
    {
        $user = $request->user();
        $data = $request->safe()->only(['nama_lengkap', 'email', 'telepon']);
        $oldPhoto = $user->foto_profil;

        // Anggota avatar comes from foto_pencairan, not users.foto_profil.
        if ($user->role !== 'anggota') {
            if ($request->boolean('hapus_foto_profil') && filled($oldPhoto)) {
                $data['foto_profil'] = null;
            }

            if ($request->hasFile('foto_profil')) {
                $data['foto_profil'] = $images->store($request->file('foto_profil'), 'users/'.$user->id);
            }
        }

        $user->update($data);

        if (
            $user->role !== 'anggota'
            && array_key_exists('foto_profil', $data)
            && filled($oldPhoto)
            && $oldPhoto !== ($data['foto_profil'] ?? null)
            && str_starts_with((string) $oldPhoto, 'users/')
        ) {
            Storage::disk('local')->delete($oldPhoto);
        }

        return to_route('profile.edit')->with('status', 'Profil berhasil diperbarui.');
    }

    public function updatePassword(UpdatePasswordRequest $request): RedirectResponse
    {
        $request->user()->forceFill([
            'password' => Hash::make($request->validated('password')),
        ])->save();

        return to_route('profile.edit')->with('status', 'Kata sandi berhasil diperbarui.');
    }

    public function photo(ResolveMemberDisbursementPhoto $disbursementPhotos): StreamedResponse|Response
    {
        $user = auth()->user();
        abort_unless($user, 404);

        if ($user->role === 'anggota') {
            $memberId = Anggota::query()->where('user_id', $user->id)->value('id');
            $path = $disbursementPhotos->handle($memberId ? (int) $memberId : null);
            abort_unless(
                is_string($path)
                && $path !== ''
                && ! str_contains($path, '..')
                && str_starts_with($path, 'pencairan/')
                && Storage::disk('local')->exists($path),
                404
            );

            return Storage::disk('local')->response($path, basename($path), [
                'Content-Type' => Storage::disk('local')->mimeType($path) ?: 'image/webp',
                'Cache-Control' => 'private, max-age=3600',
            ]);
        }

        $path = (string) ($user->foto_profil ?? '');
        abort_unless(
            $path !== ''
            && ! str_contains($path, '..')
            && str_starts_with($path, 'users/'.$user->id.'/')
            && Storage::disk('local')->exists($path),
            404
        );

        return Storage::disk('local')->response($path, basename($path), [
            'Content-Type' => Storage::disk('local')->mimeType($path) ?: 'image/webp',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
