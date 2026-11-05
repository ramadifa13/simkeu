<?php

namespace App\Actions\Audit;

use App\Actions\Notifications\BustPortalNotificationCache;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\Request;

class RecordActivity
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function handle(?User $user, string $action, string $auditableType, ?int $auditableId, array $context, Request $request): void
    {
        ActivityLog::query()->create([
            'user_id' => $user?->id,
            'action' => $action,
            'auditable_type' => $auditableType,
            'auditable_id' => $auditableId,
            'context' => $context,
            'ip_address' => $request->ip(),
            'user_agent' => str($request->userAgent())->limit(1000)->toString(),
        ]);

        app(BustPortalNotificationCache::class)->handle();
    }
}
