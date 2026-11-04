<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
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

        Gate::define('manage-master-data', fn ($user): bool => $user->role === 'admin');
        Gate::define('manage-tellers', fn ($user): bool => $user->role === 'admin');
        Gate::define('manage-cooperative-settings', fn ($user): bool => $user->role === 'admin');
        Gate::define('view-audit-log', fn ($user): bool => $user->role === 'admin');
        Gate::define('manage-savings', fn ($user): bool => in_array($user->role, ['admin', 'teller'], true));
        Gate::define('manage-waserda', fn ($user): bool => in_array($user->role, ['admin', 'teller'], true));
        Gate::define('delete-waserda', fn ($user): bool => $user->role === 'admin');
        Gate::define('view-cash-ledger', fn ($user): bool => $user->role === 'admin');
        Gate::define('manage-savings-corrections', fn ($user): bool => $user->role === 'admin');
        Gate::define('reconcile-cash', fn ($user): bool => $user->role === 'admin');
        Gate::define('view-operational-reports', fn ($user): bool => in_array($user->role, ['admin', 'teller'], true));
        Gate::define('view-admin-reports', fn ($user): bool => $user->role === 'admin');
        Gate::define('view-financial-parity', fn ($user): bool => $user->role === 'admin');
        Gate::define('manage-loan-applications', fn ($user): bool => in_array($user->role, ['admin', 'teller'], true));
        Gate::define('review-loan-applications', fn ($user): bool => $user->role === 'admin');
        Gate::define('disburse-loans', fn ($user): bool => in_array($user->role, ['admin', 'teller'], true));
        Gate::define('view-loans', fn ($user): bool => in_array($user->role, ['admin', 'teller', 'anggota'], true));
        Gate::define('collect-installments', fn ($user): bool => in_array($user->role, ['admin', 'teller'], true));
        Gate::define('review-collateral-return', fn ($user): bool => $user->role === 'admin');
        Gate::define('manage-layanan', fn ($user): bool => in_array($user->role, ['admin', 'teller'], true));
        Gate::define('decide-layanan', fn ($user): bool => $user->role === 'admin');
        Gate::define('manage-shu', fn ($user): bool => $user->role === 'admin');
        Gate::define('view-own-shu', fn ($user): bool => in_array($user->role, ['admin', 'anggota'], true));
        Gate::define('manage-migration', fn ($user): bool => $user->role === 'admin');
        Gate::define('run-backup', fn ($user): bool => $user->role === 'admin');
        Gate::define('manage-document-exports', fn ($user): bool => in_array($user->role, ['admin', 'teller', 'anggota'], true));

    }
}
