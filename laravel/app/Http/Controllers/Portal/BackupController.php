<?php

namespace App\Http\Controllers\Portal;

use App\Actions\Audit\RecordActivity;
use App\Actions\Backup\CreateOperationalBackupAction;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BackupController extends Controller
{
    public function index(string $role): View
    {
        $this->authorize('run-backup');

        return view('portal.backup.index', [
            'role' => $role,
            'latest' => session('backup_meta'),
        ]);
    }

    public function store(Request $request, string $role, CreateOperationalBackupAction $action, RecordActivity $audit): RedirectResponse
    {
        $this->authorize('run-backup');
        try {
            $meta = $action->handle($request->user());
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['backup' => $exception->getMessage()]);
        }
        $audit->handle($request->user(), 'backup.created', 'backup', null, $meta, $request);

        return redirect()->route('portal.backup.index', ['role' => $role])
            ->with('status', 'Backup operasional siap diunduh sekali.')
            ->with('backup_meta', $meta);
    }

    public function download(Request $request, string $role, string $token, RecordActivity $audit): BinaryFileResponse
    {
        $this->authorize('run-backup');
        abort_unless((bool) preg_match('/^[a-f0-9]{32}$/', $token), 404);
        $metaPath = 'backups/'.$token.'.json';
        abort_unless(Storage::disk('local')->exists($metaPath), 404);
        $meta = json_decode(Storage::disk('local')->get($metaPath), true, 512, JSON_THROW_ON_ERROR);
        abort_unless(($meta['user_id'] ?? null) === $request->user()->id, 403);
        abort_if(! empty($meta['downloaded']), 410, 'Token unduhan sudah digunakan.');
        $path = (string) ($meta['path'] ?? '');
        $backupRoot = realpath(storage_path('app/private/backups')) ?: storage_path('app/private/backups');
        $realPath = realpath($path);
        abort_unless($realPath !== false && str_starts_with($realPath, $backupRoot.DIRECTORY_SEPARATOR) && is_file($realPath), 404);
        $path = $realPath;

        $meta['downloaded'] = true;
        $meta['downloaded_at'] = now()->toDateTimeString();
        Storage::disk('local')->put($metaPath, json_encode($meta, JSON_THROW_ON_ERROR));
        $audit->handle($request->user(), 'backup.downloaded', 'backup', null, ['token' => $token, 'filename' => $meta['filename']], $request);

        return response()->download($path, $meta['filename'])->deleteFileAfterSend(true);
    }
}
