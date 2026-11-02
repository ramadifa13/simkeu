<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(string $role): View
    {
        return view('portal.dashboard', ['role' => $role]);
    }
}
