<?php

namespace App\Http\Controllers\Portal;

use App\Actions\Audit\RecordActivity;
use App\Actions\Migration\BuildMigrationTemplateAction;
use App\Actions\Migration\ExportMigrationWorkbookAction;
use App\Actions\Migration\ImportMigrationWorkbookAction;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MigrationController extends Controller
{
    public function index(string $role): View
    {
        $this->authorize('manage-migration');

        return view('portal.migration.index', [
            'role' => $role,
            'version' => BuildMigrationTemplateAction::VERSION,
            'lastResult' => session('migration_result'),
        ]);
    }

    public function template(string $role, BuildMigrationTemplateAction $action, RecordActivity $audit, Request $request): StreamedResponse
    {
        $this->authorize('manage-migration');
        $audit->handle($request->user(), 'migration.template_downloaded', 'migrasi', null, ['version' => BuildMigrationTemplateAction::VERSION], $request);

        return $action->handle();
    }

    public function export(string $role, ExportMigrationWorkbookAction $action, RecordActivity $audit, Request $request): StreamedResponse
    {
        $this->authorize('manage-migration');
        $audit->handle($request->user(), 'migration.exported', 'migrasi', null, ['version' => BuildMigrationTemplateAction::VERSION], $request);

        return $action->handle($request->user());
    }

    public function import(Request $request, string $role, ImportMigrationWorkbookAction $action, RecordActivity $audit): RedirectResponse
    {
        $this->authorize('manage-migration');
        $request->validate([
            'workbook' => ['required', 'file', 'mimes:xlsx,xls', 'max:10240'],
            'dry_run' => ['nullable', 'boolean'],
        ]);
        // Checkbox omitted when unchecked — default remains dry-run for safety.
        $dryRun = $request->boolean('dry_run', true);
        try {
            $result = $action->handle($request->file('workbook'), $dryRun, $request->user());
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['workbook' => $exception->getMessage()]);
        }
        $audit->handle($request->user(), $dryRun ? 'migration.dry_run' : 'migration.imported', 'migrasi', null, $result, $request);

        return redirect()->route('portal.migration.index', ['role' => $role])
            ->with('status', $dryRun ? 'Dry-run selesai. Periksa ringkasan sebelum impor final.' : 'Impor migrasi selesai.')
            ->with('migration_result', $result);
    }
}
