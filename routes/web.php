<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AdminDashboardController;

Route::get('/', [AdminDashboardController::class, 'index'])->name('dashboard');
Route::post('/refresh', [AdminDashboardController::class, 'refresh'])->name('dashboard.refresh');
Route::get('/api/system/{systemId}/energy-flow', [AdminDashboardController::class, 'getSystemEnergyFlow'])->name('api.system.energy-flow');
Route::get('/api/system/{systemId}/realtime', [AdminDashboardController::class, 'getSystemRealtime'])->name('api.system.realtime');
