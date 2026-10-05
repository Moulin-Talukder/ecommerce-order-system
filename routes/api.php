<?php

use App\Http\Controllers\Webhooks\SteadfastWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Stateless endpoints (no session, no CSRF)
|--------------------------------------------------------------------------
|
| Third-party webhooks authenticate themselves (see each controller).
|
*/

Route::post('webhooks/steadfast', SteadfastWebhookController::class)
    ->middleware('throttle:120,1')
    ->name('webhooks.steadfast');
