<?php

use App\Http\Controllers\Admin\BrandController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\OrderCourierController;
use App\Http\Controllers\Admin\OrderPaymentController;
use App\Http\Controllers\Admin\OrderStatusController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\ProductImageController;
use App\Http\Controllers\Admin\ProductStockController;
use App\Http\Controllers\Storefront\CartController;
use App\Http\Controllers\Storefront\CatalogController;
use App\Http\Controllers\Storefront\CheckoutController;
use App\Http\Controllers\Storefront\OrderConfirmationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Customer storefront (guest)
|--------------------------------------------------------------------------
*/

Route::name('storefront.')->group(function () {
    Route::get('/', [CatalogController::class, 'index'])->name('home');
    Route::get('categories/{slug}', [CatalogController::class, 'category'])->name('category');
    Route::get('products/{slug}', [CatalogController::class, 'product'])->name('product');
    Route::livewire('cart', 'pages::storefront.cart')->name('cart');
    Route::post('cart/items', [CartController::class, 'store'])->name('cart.add');
    Route::get('checkout', [CheckoutController::class, 'show'])->name('checkout');
    Route::post('checkout', [CheckoutController::class, 'store'])->middleware('throttle:storefront-checkout')->name('checkout.store');
    Route::get('order/{orderNumber}/thank-you', [OrderConfirmationController::class, 'show'])->name('thank-you');
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
        Route::post('orders/{order}/courier', [OrderCourierController::class, 'book'])->name('orders.courier.book');
        Route::post('orders/{order}/courier/sync', [OrderCourierController::class, 'sync'])->name('orders.courier.sync');

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
