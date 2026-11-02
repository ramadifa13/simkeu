<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class LandingController extends Controller
{
    public function __invoke(): View
    {
        $cooperative = Schema::hasTable('koperasi')
            ? DB::table('koperasi')->latest('id')->first(['nama', 'alamat', 'telepon', 'email'])
            : null;

        return view('landing', ['cooperative' => $cooperative]);
    }
}
