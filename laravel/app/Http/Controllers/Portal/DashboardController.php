<?php

namespace App\Http\Controllers\Portal;

use App\Actions\Dashboard\GetDashboardData;
use App\Http\Controllers\Controller;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, string $role, GetDashboardData $getDashboardData): View|JsonResponse
    {
        try {
            $dashboard = $getDashboardData->handle($request->user(), $role);
        } catch (QueryException $exception) {
            Log::warning('Dashboard query gagal dimuat.', [
                'role' => $role,
                'user_id' => $request->user()?->id,
                'exception' => $exception->getMessage(),
            ]);

            $dashboard = [
                'roleLabel' => ucfirst($role),
                'intro' => 'Ringkasan operasional belum dapat dimuat.',
                'cards' => [],
                'alerts' => [],
                'activity' => collect(),
                'periodLabel' => 'Silakan muat ulang dashboard.',
                'error' => true,
            ];
        }

        if ($request->expectsJson()) {
            return response()->json([
                'cards' => $dashboard['cards'] ?? [],
                'queue' => $dashboard['queue'] ?? [],
                'periodLabel' => $dashboard['periodLabel'] ?? '',
            ]);
        }

        return view('portal.dashboard', [
            'role' => $role,
            'dashboard' => $dashboard,
        ]);
    }
}
