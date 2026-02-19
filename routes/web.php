<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AdminDashboardController;
use App\Http\Controllers\LoginController;

Route::get('/login', [LoginController::class, 'showLogin'])->name('login');
Route::post('/login', [LoginController::class, 'login']);
Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

Route::middleware('password.auth')->group(function () {
    Route::get('/', [AdminDashboardController::class, 'index'])->name('dashboard');
    Route::post('/refresh', [AdminDashboardController::class, 'refresh'])->name('dashboard.refresh');
    Route::get('/api/system/{systemId}/energy-flow', [AdminDashboardController::class, 'getSystemEnergyFlow'])->name('api.system.energy-flow');
    Route::get('/api/system/{systemId}/realtime', [AdminDashboardController::class, 'getSystemRealtime'])->name('api.system.realtime');
});
