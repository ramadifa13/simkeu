<?php

namespace App\Http\Controllers\Portal;

use App\Actions\Audit\RecordActivity;
use App\Actions\Members\CreateMemberAction;
use App\Actions\Members\GetMemberDetailData;
use App\Actions\Members\ResetMemberPasswordAction;
use App\Actions\Members\ResolveMemberDisbursementPhoto;
use App\Actions\Members\UpdateMemberAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMemberRequest;
use App\Http\Requests\UpdateMemberRequest;
use App\Models\Anggota;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MemberController extends Controller
{
    public function create(string $role): View
    {
        $this->authorize('create', Anggota::class);

        return view('portal.members.create', ['role' => $role, 'groupLeaders' => $this->groupLeaders()]);
    }

    public function store(StoreMemberRequest $request, string $role, CreateMemberAction $createMember, RecordActivity $recordActivity): RedirectResponse
    {
        $this->authorize('create', Anggota::class);

        $created = $createMember->handle($request->validated());
        $member = $created['member'];
        $recordActivity->handle($request->user(), 'member.created', Anggota::class, $member->id, ['kode_anggota' => $member->kode_anggota], $request);

        return redirect()
            ->route('portal.members.show', ['role' => $role, 'member' => $member])
            ->with('status', 'Anggota berhasil dibuat. Password awal (sekali tampil): '.$created['password']);
    }

    public function edit(string $role, Anggota $member): View
    {
        $this->authorize('update', $member);

        return view('portal.members.edit', ['member' => $member, 'role' => $role, 'groupLeaders' => $this->groupLeaders($member)]);
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

    public function resetPassword(Request $request, string $role, Anggota $member, ResetMemberPasswordAction $resetPassword, RecordActivity $recordActivity): RedirectResponse
    {
        $this->authorize('resetPassword', $member);

        $reset = $resetPassword->handle($member);
        $recordActivity->handle($request->user(), 'member.password_reset', Anggota::class, $member->id, ['kode_anggota' => $member->kode_anggota], $request);

        return back()->with('status', "Password {$member->kode_anggota} direset. Sandi baru (sekali tampil): {$reset['password']}");
    }

    public function destroy(Request $request, string $role, Anggota $member, RecordActivity $recordActivity): RedirectResponse
    {
        $this->authorize('delete', $member);

        if ($member->status !== 'nonaktif') {
            return back()->with('error', "Tidak dapat menghapus {$member->nama_lengkap}. Status masih Aktif — ubah dulu ke Nonaktif di halaman edit, lalu coba hapus lagi.");
        }

        $referenceLabels = [
            'simpanan' => 'rekening simpanan',
            'transaksi' => 'riwayat transaksi simpanan',
            'pinjaman' => 'data pinjaman',
            'pengajuan_pinjaman' => 'pengajuan pinjaman',
            'shu_anggota' => 'hak SHU',
        ];

        $references = collect([
            'simpanan' => ['anggota_id' => $member->id],
            'transaksi' => ['anggota_id' => $member->id],
            'pinjaman' => ['anggota_id' => $member->id],
            'pengajuan_pinjaman' => ['anggota_id' => $member->id],
            'shu_anggota' => ['anggota_id' => $member->id],
        ])->filter(fn (array $where, string $table): bool => DB::getSchemaBuilder()->hasTable($table) && DB::table($table)->where($where)->exists());

        if ($references->isNotEmpty()) {
            $reasons = $references->keys()
                ->map(fn (string $table): string => $referenceLabels[$table] ?? $table)
                ->values()
                ->all();
            $last = array_pop($reasons);
            $list = $reasons === [] ? $last : implode(', ', $reasons).' dan '.$last;

            return back()->with('error', "Tidak dapat menghapus {$member->nama_lengkap} karena masih punya {$list}. Data keuangan tidak dihapus demi audit — biarkan status Nonaktif.");
        }

        $code = $member->kode_anggota;
        $userId = $member->user_id;
        $documents = collect([$member->foto_ktp, $member->foto_kk, $member->foto_ktp_pasangan])->filter(fn ($path) => str_starts_with((string) $path, 'anggota/'));

        DB::transaction(function () use ($member, $userId): void {
            $member->delete();
            if ($userId) {
                DB::table('users')->where('id', $userId)->where('role', 'anggota')->delete();
            }
        });
        $documents->each(fn (string $path) => Storage::disk('local')->delete($path));
        $recordActivity->handle($request->user(), 'member.deleted', Anggota::class, $member->id, ['kode_anggota' => $code], $request);

        return redirect()->route('portal.members.index', ['role' => $role])->with('status', "Anggota {$code} berhasil dihapus.");
    }

    public function show(Request $request, string $role, Anggota $member, GetMemberDetailData $getMemberDetailData): View
    {
        $this->authorize('view', $member);

        $member->loadMissing('ketuaKelompok:id,kode_anggota,nama_lengkap');

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
            'disbursementPhoto' => app(ResolveMemberDisbursementPhoto::class)->handle((int) $member->id),
        ]);
    }

    public function document(string $role, Anggota $member, string $document): StreamedResponse
    {
        $this->authorize('viewDocument', $member);

        abort_unless(in_array($document, ['foto_ktp', 'foto_kk', 'foto_ktp_pasangan'], true), 404);

        $path = (string) $member->{$document};
        abort_unless(
            $path !== ''
            && ! str_contains($path, '..')
            && str_starts_with($path, "anggota/{$member->id}/")
            && Storage::disk('local')->exists($path),
            404
        );

        return Storage::disk('local')->response($path, basename($path), [
            'Content-Disposition' => 'inline; filename="'.basename($path).'"',
        ]);
    }

    /** Serve foto nasabah menerima uang (foto_pencairan) as member avatar. */
    public function avatar(string $role, Anggota $member, ResolveMemberDisbursementPhoto $photos): StreamedResponse|Response
    {
        $this->authorize('view', $member);
        $path = $photos->handle((int) $member->id);
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
            'Content-Disposition' => 'inline; filename="'.basename($path).'"',
        ]);
    }

    public function export(Request $request, string $role, RecordActivity $recordActivity): StreamedResponse
    {
        $this->authorize('export', Anggota::class);

        $search = trim($request->string('search')->toString());
        $status = $request->string('status')->toString();
        abort_unless(in_array($status, ['', 'aktif', 'nonaktif'], true), 422);

        $members = Anggota::query()
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('nama_lengkap', 'like', "%{$search}%")
                        ->orWhere('kode_anggota', 'like', "%{$search}%")
                        ->orWhere('telepon', 'like', "%{$search}%");
                });
            })
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->orderBy('nama_lengkap')
            ->get(['kode_anggota', 'nama_lengkap', 'telepon', 'email', 'alamat', 'status', 'tanggal_daftar']);

        $recordActivity->handle($request->user(), 'member.exported', Anggota::class, null, [
            'count' => $members->count(),
            'has_search' => $search !== '',
            'status' => $status ?: 'semua',
        ], $request);

        return response()->streamDownload(function () use ($members): void {
            $handle = fopen('php://output', 'wb');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['Kode anggota', 'Nama lengkap', 'Telepon', 'Email', 'Alamat', 'Status', 'Tanggal daftar']);

            foreach ($members as $member) {
                fputcsv($handle, [$member->kode_anggota, $member->nama_lengkap, $member->telepon, $member->email, $member->alamat, $member->status, $member->tanggal_daftar]);
            }

            fclose($handle);
        }, 'anggota-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return Collection<int, Anggota> */
    private function groupLeaders(?Anggota $except = null): Collection
    {
        return Anggota::query()
            ->where('kategori_shu', 'ketua_kelompok')
            ->where('status', 'aktif')
            ->when($except, fn ($query) => $query->whereKeyNot($except->id))
            ->orderBy('nama_lengkap')
            ->get(['id', 'kode_anggota', 'nama_lengkap']);
    }
}
