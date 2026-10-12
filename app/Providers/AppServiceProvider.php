<?php

namespace App\Providers;

use App\Services\Storefront\Catalog;
use App\Support\PhoneNumber;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One category tree per request for all storefront lookups.
        $this->app->scoped(Catalog::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Surface N+1 queries during development and tests; production only logs nothing.
        Model::preventLazyLoading(! $this->app->isProduction());

        RateLimiter::for('storefront-checkout', function (Request $request): array {
            $tooMany = fn () => to_route('storefront.checkout')->withInput()
                ->with('error', 'Too many checkout attempts. Please wait a few minutes and try again.');
            $phone = PhoneNumber::normalize((string) $request->input('phone'));

            return array_filter([
                Limit::perMinute((int) config('storefront.checkout_attempts_per_minute_per_ip'))->by('checkout-ip:'.$request->ip())->response($tooMany),
                $phone ? Limit::perHour((int) config('storefront.checkout_attempts_per_hour_per_phone'))->by('checkout-phone:'.$phone)->response($tooMany) : null,
            ]);
        });
    }
}
