<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use Illuminate\View\View;

class ModulePlaceholderController extends Controller
{
    public function __invoke(string $role, string $module): View|Response
    {
        $modules = config('navigation.modules');

        abort_unless(isset($modules[$module]), 404);
        abort_unless($this->moduleIsAvailableForRole($role, $module), 403);

        return response()->view('portal.modules.placeholder', [
            'role' => $role,
            'module' => $modules[$module],
        ], 503);
    }

    private function moduleIsAvailableForRole(string $role, string $module): bool
    {
        foreach (config("navigation.{$role}", []) as $item) {
            if (($item['module'] ?? null) === $module) {
                return true;
            }

            foreach ($item['items'] ?? [] as $child) {
                if (($child['module'] ?? null) === $module) {
                    return true;
                }
            }
        }

        return false;
    }
}
