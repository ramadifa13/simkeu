<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BrandLogoController extends Controller
{
    public function __invoke(): StreamedResponse
    {
        $path = (string) (DB::table('koperasi')->orderBy('id')->value('logo') ?? '');

        abort_unless(
            Str::startsWith($path, 'koperasi/') && Storage::disk('local')->exists($path),
            404,
        );

        return Storage::disk('local')->response($path, null, [
            'Cache-Control' => 'public, max-age=300, must-revalidate',
            'Content-Disposition' => 'inline',
        ]);
    }
}
