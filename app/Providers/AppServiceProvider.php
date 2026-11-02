<?php

namespace App\Providers;

use App\Services\Audit\AuthenticationAuditor;
use App\Services\Storefront\Catalog;
use App\Services\Tracking\DataLayer;
use App\Support\PhoneNumber;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
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
        $this->app->scoped(DataLayer::class);
        // Scoped so a forced logout marked by the admin middleware reaches the Logout listener.
        $this->app->scoped(AuthenticationAuditor::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Surface N+1 queries during development and tests; production only logs nothing.
        Model::preventLazyLoading(! $this->app->isProduction());

        // Sign-in audit trail (best-effort; see AuthenticationAuditor).
        Event::listen(Login::class, [AuthenticationAuditor::class, 'handleLogin']);
        Event::listen(Failed::class, [AuthenticationAuditor::class, 'handleFailed']);
        Event::listen(Lockout::class, [AuthenticationAuditor::class, 'handleLockout']);
        Event::listen(Logout::class, [AuthenticationAuditor::class, 'handleLogout']);

        RateLimiter::for('storefront-checkout', function (Request $request): array {
            $tooMany = fn () => to_route('storefront.checkout')->withInput()
                ->with('error', 'Too many checkout attempts. Please wait a few minutes and try again.');
            $phone = PhoneNumber::normalize((string) $request->input('phone'));

            return array_filter([
                Limit::perMinute((int) config('storefront.checkout_attempts_per_minute_per_ip'))->by('checkout-ip:'.$request->ip())->response($tooMany),
                $phone ? Limit::perHour((int) config('storefront.checkout_attempts_per_hour_per_phone'))->by('checkout-phone:'.$phone)->response($tooMany) : null,
            ]);
        });

        RateLimiter::for('storefront-cart', fn (Request $request) => Limit::perMinute((int) config('storefront.cart_adds_per_minute_per_ip'))
            ->by('cart-ip:'.$request->ip())
            ->response(fn () => back()->with('error', 'Too many requests. Please wait a minute and try again.')));

        RateLimiter::for('storefront-consent', fn (Request $request) => Limit::perMinute((int) config('storefront.consent_updates_per_minute_per_ip'))
            ->by('consent-ip:'.$request->ip())
            ->response(fn () => back()->with('error', 'Too many requests. Please wait a minute and try again.')));
    }
}
