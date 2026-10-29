<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
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
        Fortify::loginView(fn () => view('auth.login'));

        Fortify::authenticateUsing(function (Request $request): ?User {
            $user = User::query()
                ->where('email', Str::lower($request->string(Fortify::username())))
                ->first();

            // Unknown emails still pay for one hash check, so response time does not reveal which accounts exist.
            if (! Hash::check($request->string('password'), $user?->password ?? self::dummyHash())) {
                return null;
            }

            if (! $user) {
                return null;
            }

            if (! $user->canAccessAdmin()) {
                throw ValidationException::withMessages([
                    Fortify::username() => __('Your account has been deactivated. Please contact an administrator.'),
                ]);
            }

            return $user;
        });

        // 5 attempts per minute per email+IP, and 20 per minute per IP across all emails (credential stuffing).
        RateLimiter::for('login', function (Request $request): array {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return [
                Limit::perMinute(5)->by($throttleKey),
                Limit::perMinute(20)->by('login-ip:'.$request->ip()),
            ];
        });
    }

    /**
     * A valid hash of a random value, computed once per process.
     */
    private static function dummyHash(): string
    {
        static $hash;

        return $hash ??= Hash::make(Str::random(40));
    }
}
