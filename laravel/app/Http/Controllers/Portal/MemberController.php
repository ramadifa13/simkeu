<?php

namespace App\Http\Controllers\Portal;

use App\Actions\Audit\RecordActivity;
use App\Actions\Members\CreateMemberAction;
use App\Actions\Members\GetMemberDetailData;
use App\Actions\Members\UpdateMemberAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMemberRequest;
use App\Http\Requests\UpdateMemberRequest;
use App\Models\Anggota;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MemberController extends Controller
{
    public function create(string $role): View
    {
        $this->authorize('create', Anggota::class);

        return view('portal.members.create', ['role' => $role]);
    }

    public function store(StoreMemberRequest $request, string $role, CreateMemberAction $createMember, RecordActivity $recordActivity): RedirectResponse
    {
        $this->authorize('create', Anggota::class);

        $member = $createMember->handle($request->validated());
        $recordActivity->handle($request->user(), 'member.created', Anggota::class, $member->id, ['kode_anggota' => $member->kode_anggota], $request);

        return redirect()
            ->route('portal.members.show', ['role' => $role, 'member' => $member])
            ->with('status', 'Anggota berhasil dibuat. Password awal akun: 123456.');
    }

    public function edit(string $role, Anggota $member): View
    {
        $this->authorize('update', $member);

        return view('portal.members.edit', ['member' => $member, 'role' => $role]);
    }

    public function update(UpdateMemberRequest $request, string $role, Anggota $member, UpdateMemberAction $updateMember, RecordActivity $recordActivity): RedirectResponse
    {
        $this->authorize('update', $member);

        $member = $updateMember->handle($member, $request->validated());
        $recordActivity->handle($request->user(), 'member.updated', Anggota::class, $member->id, ['kode_anggota' => $member->kode_anggota], $request);

        return redirect()
            ->route('portal.members.show', ['role' => $role, 'member' => $member])
            ->with('status', 'Data anggota berhasil diperbarui.');
    }

    public function show(Request $request, string $role, Anggota $member, GetMemberDetailData $getMemberDetailData): View
    {
        $this->authorize('view', $member);

        $tabs = ['profil', 'dokumen', 'simpanan', 'transaksi', 'pinjaman', 'shu', 'audit'];
        $activeTab = $request->string('tab', 'profil')->toString();

        if (! in_array($activeTab, $tabs, true)) {
            abort(404);
        }

        return view('portal.members.show', [
            'activeTab' => $activeTab,
            'member' => $member,
            'role' => $role,
            'tabs' => $tabs,
            'tabData' => $getMemberDetailData->handle($member, $activeTab),
        ]);
    }

    public function document(Anggota $member, string $document): StreamedResponse
    {
        $this->authorize('viewDocument', $member);

        abort_unless(in_array($document, ['foto_ktp', 'foto_kk', 'foto_ktp_pasangan'], true), 404);

        $path = (string) $member->{$document};
        abort_unless(str_starts_with($path, "anggota/{$member->id}/") && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, basename($path), [
            'Content-Disposition' => 'inline; filename="'.basename($path).'"',
        ]);
    }
}
