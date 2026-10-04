<?php

use App\Http\Controllers\Admin\BrandController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\OrderPaymentController;
use App\Http\Controllers\Admin\OrderStatusController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\ProductImageController;
use App\Http\Controllers\Admin\ProductStockController;
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

        Route::resource('orders', OrderController::class)->except('destroy');
        Route::patch('orders/{order}/status', [OrderStatusController::class, 'update'])->name('orders.status');
        Route::post('orders/{order}/payments', [OrderPaymentController::class, 'store'])->name('orders.payments.store');
        Route::post('orders/{order}/refunds', [OrderPaymentController::class, 'refund'])->name('orders.refunds.store');

        Route::resource('customers', CustomerController::class);
        Route::patch('customers/{customer}/status', [CustomerController::class, 'updateStatus'])->name('customers.status');

        Route::resource('products', ProductController::class);
        Route::patch('products/{product}/status', [ProductController::class, 'updateStatus'])->name('products.status');
        Route::post('products/{product}/stock-adjustments', [ProductStockController::class, 'store'])->name('products.stock.adjust');
        Route::patch('products/{product}/availability', [ProductStockController::class, 'availability'])->name('products.stock.availability');

        Route::scopeBindings()->controller(ProductImageController::class)->group(function () {
            Route::post('products/{product}/images', 'store')->name('products.images.store');
            Route::patch('products/{product}/images/{image}', 'update')->name('products.images.update');
            Route::patch('products/{product}/images/{image}/move/{direction}', 'move')
                ->whereIn('direction', ['up', 'down', 'first'])
                ->name('products.images.move');
            Route::delete('products/{product}/images/{image}', 'destroy')->name('products.images.destroy');
        });
    });
