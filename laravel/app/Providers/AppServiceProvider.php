<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use LogicException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $connection = (string) config('database.default');
        $currentDatabase = config("database.connections.{$connection}.database");
        $nativeReferenceDatabase = config('parallel-run.native_reference_database');

        if (app()->isProduction() && filled($nativeReferenceDatabase) && $currentDatabase === $nativeReferenceDatabase) {
            throw new LogicException('Laravel tidak boleh menggunakan database referensi native sebagai koneksi produksi.');
        }

        RateLimiter::for('login', function (Request $request): Limit {
            return Limit::perMinute(5)->by(Str::lower((string) $request->input('username')).'|'.$request->ip());
        });

    }
}
