<?php

use App\Http\Controllers\Account\ProfileController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Portal\DashboardController;
use App\Http\Controllers\Portal\MemberController;
use App\Http\Controllers\Portal\ModulePlaceholderController;
use App\Http\Controllers\Public\LandingController;
use Illuminate\Support\Facades\Route;

Route::get('/', LandingController::class)->name('landing');
Route::view('/login', 'auth.login')->name('login');
Route::post('/login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:login')->name('login.store');
Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->middleware('auth')->name('logout');

Route::middleware('auth')->group(function (): void {
    Route::get('/akun', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/akun', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/akun/kata-sandi', [ProfileController::class, 'updatePassword'])->name('profile.password.update');
});

Route::middleware(['auth', 'role'])->prefix('portal')->name('portal.')->group(function (): void {
    Route::get('/{role}/dashboard', DashboardController::class)
        ->whereIn('role', ['admin', 'teller', 'anggota'])
        ->name('dashboard');

    Route::view('/{role}/anggota', 'portal.members.index')
        ->whereIn('role', ['admin', 'teller'])
        ->name('members.index');

    Route::get('/{role}/anggota/tambah', [MemberController::class, 'create'])
        ->whereIn('role', ['admin', 'teller'])
        ->name('members.create');
    Route::post('/{role}/anggota', [MemberController::class, 'store'])
        ->whereIn('role', ['admin', 'teller'])
        ->name('members.store');

    Route::get('/{role}/anggota/{member}/edit', [MemberController::class, 'edit'])
        ->whereIn('role', ['admin', 'teller'])
        ->name('members.edit');
    Route::put('/{role}/anggota/{member}', [MemberController::class, 'update'])
        ->whereIn('role', ['admin', 'teller'])
        ->name('members.update');
    Route::get('/{role}/anggota/{member}/dokumen/{document}', [MemberController::class, 'document'])
        ->whereIn('role', ['admin', 'teller'])
        ->name('members.documents.show');

    Route::get('/{role}/anggota/{member}', [MemberController::class, 'show'])
        ->whereIn('role', ['admin', 'teller'])
        ->name('members.show');

    Route::get('/{role}/modul/{module}', ModulePlaceholderController::class)
        ->whereIn('role', ['admin', 'teller', 'anggota'])
        ->name('modules.placeholder');
});
