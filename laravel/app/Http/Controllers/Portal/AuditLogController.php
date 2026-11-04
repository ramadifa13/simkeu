<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class AuditLogController extends Controller
{
    public function __invoke(Request $request, string $role): View
    {
        Gate::authorize('view-audit-log');

        $search = trim($request->string('search')->toString());
        $action = $request->string('action')->toString();
        $perPage = (int) $request->integer('per_page', 10);
        $perPage = in_array($perPage, [5, 10, 25, 50], true) ? $perPage : 10;

        $logs = ActivityLog::query()
            ->leftJoin('users', 'users.id', '=', 'activity_logs.user_id')
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('activity_logs.action', 'like', "%{$search}%")
                        ->orWhere('activity_logs.auditable_type', 'like', "%{$search}%")
                        ->orWhere('users.nama_lengkap', 'like', "%{$search}%");
                });
            })
            ->when($action !== '', fn ($query) => $query->where('activity_logs.action', $action))
            ->orderByDesc('activity_logs.id')
            ->paginate($perPage, [
                'activity_logs.id', 'activity_logs.action', 'activity_logs.auditable_type', 'activity_logs.auditable_id', 'activity_logs.context', 'activity_logs.ip_address', 'activity_logs.created_at', 'users.nama_lengkap as actor',
            ])
            ->withQueryString();

        $actions = ActivityLog::query()->select('action')->distinct()->orderBy('action')->pluck('action');

        return view('portal.audit.index', compact('role', 'logs', 'actions', 'search', 'action', 'perPage'));
    }
}
