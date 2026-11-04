<?php

namespace App\Http\Controllers\Portal;

use App\Actions\Audit\RecordActivity;
use App\Actions\Tellers\DeleteTellerAction;
use App\Actions\Tellers\ResetTellerPasswordAction;
use App\Http\Controllers\Controller;
use App\Models\User;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TellerController extends Controller
{
    public function index(string $role): View
    {
        $this->authorize('manage-tellers');

        return view('portal.tellers.index', [
            'role' => $role,
        ]);
    }

    public function create(string $role): View
    {
        $this->authorize('manage-tellers');

        return view('portal.tellers.create', [
            'role' => $role,
        ]);
    }

    public function store(Request $request, string $role, RecordActivity $audit): RedirectResponse
    {
        $this->authorize('manage-tellers');

        $data = $this->validated($request);
        unset($data['password'], $data['password_confirmation']);
        $teller = User::query()->create($data);
        $teller->forceFill([
            'password' => Hash::make($request->string('password')->toString()),
            'role' => 'teller',
        ])->save();

        $audit->handle($request->user(), 'teller.created', User::class, $teller->id, [
            'username' => $teller->username,
        ], $request);

        return redirect()
            ->route('portal.tellers.index', ['role' => $role])
            ->with('status', 'Akun Teller berhasil dibuat.');
    }

    public function edit(string $role, User $teller): View
    {
        $this->authorize('manage-tellers');
        abort_unless($teller->role === 'teller', 404);

        return view('portal.tellers.edit', [
            'role' => $role,
            'teller' => $teller,
        ]);
    }

    public function update(Request $request, string $role, User $teller, RecordActivity $audit): RedirectResponse
    {
        $this->authorize('manage-tellers');
        abort_unless($teller->role === 'teller', 404);

        $data = $this->validated($request, $teller);

        // Password is optional on edit. Drop it unless the user typed a new one;
        // the legacy users.password column rejects null.
        unset($data['password'], $data['password_confirmation']);

        $teller->update($data);

        if ($request->filled('password')) {
            $teller->forceFill([
                'password' => Hash::make($request->string('password')->toString()),
            ])->save();
        }

        $audit->handle($request->user(), 'teller.updated', User::class, $teller->id, [
            'username' => $teller->username,
            'status' => $teller->status,
        ], $request);

        return redirect()
            ->route('portal.tellers.index', ['role' => $role])
            ->with('status', 'Data Teller diperbarui.');
    }

    public function resetPassword(
        Request $request,
        string $role,
        User $teller,
        ResetTellerPasswordAction $reset,
        RecordActivity $audit,
    ): RedirectResponse {
        $this->authorize('manage-tellers');
        abort_unless($teller->role === 'teller', 404);

        $resetResult = $reset->handle($teller);

        $audit->handle($request->user(), 'teller.password_reset', User::class, $teller->id, [
            'username' => $teller->username,
        ], $request);

        return back()->with('status', "Password {$teller->username} direset. Sandi baru (sekali tampil): {$resetResult['password']}");
    }

    public function destroy(
        Request $request,
        string $role,
        User $teller,
        DeleteTellerAction $delete,
        RecordActivity $audit,
    ): RedirectResponse {
        $this->authorize('manage-tellers');
        abort_unless($teller->role === 'teller', 404);

        try {
            $username = $teller->username;
            $id = $teller->id;
            $delete->handle($teller);
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $audit->handle($request->user(), 'teller.deleted', User::class, $id, [
            'username' => $username,
        ], $request);

        return redirect()
            ->route('portal.tellers.index', ['role' => $role])
            ->with('status', 'Akun Teller dihapus.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?User $teller = null): array
    {
        return $request->validate([
            'username' => ['required', 'alpha_dash', 'max:50', Rule::unique('users', 'username')->ignore($teller)],
            'nama_lengkap' => ['required', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:100'],
            'telepon' => ['nullable', 'string', 'max:20'],
            'status' => ['required', Rule::in(['aktif', 'nonaktif'])],
            'password' => [$teller ? 'nullable' : 'required', 'string', 'min:8', 'confirmed'],
        ]);
    }
}
