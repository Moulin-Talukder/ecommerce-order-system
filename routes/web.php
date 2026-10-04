<?php

use App\Http\Controllers\Admin\BrandController;
use App\Http\Controllers\Admin\CategoryController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
|--------------------------------------------------------------------------
| Admin panel
|--------------------------------------------------------------------------
|
| Login/logout routes are registered by Laravel Fortify. Every admin route
| requires an authenticated, active user with an admin-panel role. Finer
| per-action permissions are enforced by model policies (app/Policies).
|
*/

Route::middleware(['auth', 'admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::livewire('/', 'pages::admin.dashboard')->name('dashboard');

        Route::resource('categories', CategoryController::class)->except('show');
        Route::patch('categories/{category}/status', [CategoryController::class, 'updateStatus'])->name('categories.status');

        Route::resource('brands', BrandController::class)->except('show');
        Route::patch('brands/{brand}/status', [BrandController::class, 'updateStatus'])->name('brands.status');
    });
