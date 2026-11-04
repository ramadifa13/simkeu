<?php

namespace App\Http\Controllers\Portal;

use App\Actions\Finance\BuildPhaseSixParityReport;
use App\Http\Controllers\Controller;
use Illuminate\View\View;

class FinancialParityController extends Controller
{
    public function __invoke(string $role, BuildPhaseSixParityReport $parity): View
    {
        $this->authorize('view-financial-parity');

        return view('portal.reports.parity', [
            'role' => $role,
            'report' => $parity->handle(),
        ]);
    }
}
