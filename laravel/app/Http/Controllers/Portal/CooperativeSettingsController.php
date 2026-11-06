<?php

namespace App\Http\Controllers\Portal;

use App\Actions\Audit\RecordActivity;
use App\Http\Controllers\Controller;
use App\Support\ImageLimits;
use App\Support\ImageStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

class CooperativeSettingsController extends Controller
{
    public function edit(string $role): View
    {
        Gate::authorize('manage-cooperative-settings');

        return view('portal.settings.cooperative', [
            'role' => $role,
            'cooperative' => DB::table('koperasi')->orderBy('id')->first(),
        ]);
    }

    public function agreement(string $role): View
    {
        Gate::authorize('manage-cooperative-settings');

        return view('portal.settings.agreement', [
            'role' => $role,
            'agreement' => DB::table('pengaturan_perjanjian')->where('id', 1)->first(),
            'serviceSettings' => DB::table('layanan_pengaturan')->orderBy('id')->first(),
        ]);
    }

    public function logo(string $role)
    {
        abort_unless(auth()->check(), 403);

        $path = (string) (DB::table('koperasi')->orderBy('id')->value('logo') ?? '');

        abort_unless(
            $path !== ''
            && ! str_contains($path, '..')
            && Str::startsWith($path, 'koperasi/')
            && Storage::disk('local')->exists($path),
            404,
        );

        return Storage::disk('local')->response($path, null, [
            'Cache-Control' => 'private, no-store, must-revalidate',
            'Content-Disposition' => 'inline',
        ]);
    }

    public function signature(string $role)
    {
        Gate::authorize('manage-cooperative-settings');

        $path = (string) (DB::table('pengaturan_perjanjian')->where('id', 1)->value('ttd_admin') ?? '');

        abort_unless(
            $path !== ''
            && ! str_contains($path, '..')
            && Str::startsWith($path, 'perjanjian/tanda-tangan/')
            && Storage::disk('local')->exists($path),
            404,
        );

        return Storage::disk('local')->response($path, null, [
            'Cache-Control' => 'private, no-store, must-revalidate',
            'Content-Disposition' => 'inline',
        ]);
    }

    public function update(Request $request, string $role, ImageStorage $images, RecordActivity $activity): RedirectResponse
    {
        Gate::authorize('manage-cooperative-settings');

        $cooperative = DB::table('koperasi')->orderBy('id')->first();
        $validated = $request->validate([
            'nama' => ['required', 'string', 'max:255'],
            'alamat' => ['nullable', 'string', 'max:2000'],
            'telepon' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'rekening_bank' => ['nullable', 'string', 'max:255'],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.ImageLimits::SOURCE_MAX_KILOBYTES],
        ]);

        $oldLogo = $cooperative?->logo;
        $payload = collect($validated)->except('logo')->all();

        if ($request->hasFile('logo')) {
            $payload['logo'] = $images->store($request->file('logo'), 'koperasi');
        }

        if ($cooperative) {
            DB::table('koperasi')->where('id', $cooperative->id)->update($payload);
            $cooperativeId = (int) $cooperative->id;
        } else {
            $cooperativeId = (int) DB::table('koperasi')->insertGetId($payload);
        }

        if (isset($payload['logo'])) {
            $this->deleteReplacedPrivateFile($oldLogo, $payload['logo'], 'koperasi/');
        }

        $activity->handle($request->user(), 'updated', 'koperasi', $cooperativeId, [
            'changed' => array_keys($payload),
        ], $request);

        return back()->with('status', 'Profil koperasi berhasil diperbarui.');
    }

    public function updateAgreement(Request $request, string $role, ImageStorage $images, RecordActivity $activity): RedirectResponse
    {
        Gate::authorize('manage-cooperative-settings');

        $validated = $request->validate([
            'nama_pihak_pertama' => ['nullable', 'string', 'max:120'],
            'nik_pihak_pertama' => ['nullable', 'digits_between:12,30'],
            'alamat_pihak_pertama' => ['nullable', 'string', 'max:2000'],
            'telepon_pihak_pertama' => ['nullable', 'string', 'max:30'],
            'jabatan_admin' => ['required', 'string', 'max:100'],
            'template_default' => ['required', 'in:ringkas,lengkap'],
            'isi_ringkas' => ['required', 'string', 'max:10000'],
            'isi_lengkap' => ['required', 'string', 'max:30000'],
            'ttd_admin' => ['nullable', 'image', 'mimes:webp,png,jpg,jpeg', 'max:2048'],
            'diskon_pelunasan' => ['required', 'numeric', 'between:0,100'],
            'pemutihan' => ['nullable', 'boolean'],
            'denda_jaminan_per_juta' => ['required', 'numeric', 'min:0'],
            'bonus_persen' => ['required', 'numeric', 'between:0,100'],
            'wa_denda' => ['required', 'string', 'max:2000'],
            'wa_lunas' => ['required', 'string', 'max:2000'],
            'surat_lunas' => ['nullable', 'string', 'max:4000'],
            'bast_jaminan' => ['nullable', 'string', 'max:4000'],
        ]);

        $current = DB::table('pengaturan_perjanjian')->where('id', 1)->first();
        $payload = collect($validated)->except([
            'ttd_admin',
            'diskon_pelunasan',
            'pemutihan',
            'denda_jaminan_per_juta',
            'bonus_persen',
            'wa_denda',
            'wa_lunas',
            'surat_lunas',
            'bast_jaminan',
        ])->all();
        $oldSignature = $current?->ttd_admin;

        if ($request->hasFile('ttd_admin')) {
            $payload['ttd_admin'] = $images->store($request->file('ttd_admin'), 'perjanjian/tanda-tangan');
        }

        $payload['updated_by'] = $request->user()->id;
        $payload['updated_at'] = now();
        DB::table('pengaturan_perjanjian')->updateOrInsert(['id' => 1], $payload);

        $servicePayload = [
            'diskon_pelunasan' => $validated['diskon_pelunasan'],
            'pemutihan' => $request->boolean('pemutihan'),
            'denda_jaminan_per_juta' => $validated['denda_jaminan_per_juta'],
            'bonus_persen' => $validated['bonus_persen'],
            'wa_denda' => $validated['wa_denda'],
            'wa_lunas' => $validated['wa_lunas'],
            'surat_lunas' => $validated['surat_lunas'] ?? null,
            'bast_jaminan' => $validated['bast_jaminan'] ?? null,
        ];
        $serviceSettings = DB::table('layanan_pengaturan')->orderBy('id')->first();
        if ($serviceSettings) {
            DB::table('layanan_pengaturan')->where('id', $serviceSettings->id)->update($servicePayload);
        } else {
            DB::table('layanan_pengaturan')->insert(['id' => 1, ...$servicePayload]);
        }

        if (isset($payload['ttd_admin'])) {
            $this->deleteReplacedPrivateFile($oldSignature, $payload['ttd_admin'], 'perjanjian/tanda-tangan/');
        }

        $activity->handle($request->user(), 'agreement_settings.updated', 'pengaturan_perjanjian', 1, [
            'changed' => array_keys($payload),
            'service_changed' => array_keys($servicePayload),
        ], $request);

        return back()->with('status', 'Pengaturan perjanjian dan layanan berhasil diperbarui.');
    }

    private function deleteReplacedPrivateFile(?string $oldPath, string $newPath, string $prefix): void
    {
        if (filled($oldPath) && $oldPath !== $newPath && Str::startsWith($oldPath, $prefix)) {
            Storage::disk('local')->delete($oldPath);
        }

        // Hapus sisa file lama di folder yang sama agar preview tidak memakai aset yatim.
        foreach (Storage::disk('local')->files(rtrim($prefix, '/')) as $path) {
            if ($path !== $newPath) {
                Storage::disk('local')->delete($path);
            }
        }
    }
}
