<?php

namespace App\Services\Tracking;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Reads and writes the storefront tracking-consent choice.
 *
 * Stored only in the "storefront_consent" cookie, which Laravel encrypts
 * (EncryptCookies), as {"v": version, "a": analytics, "m": marketing,
 * "t": decided-at unix time}. Anything missing, malformed, tampered with,
 * from another consent version or older than the configured lifetime is
 * treated as "no choice" - never as permission.
 */
class Consent
{
    public const COOKIE = 'storefront_consent';

    /** Cookies set by Google Analytics and the Meta Pixel. */
    private const ANALYTICS_COOKIES = '/^_ga(_.+)?$/';

    private const MARKETING_COOKIES = '/^_fb[pc]$/';

    /** Tolerated clock skew for a decided-at time in the future. */
    private const MAX_FUTURE_SECONDS = 300;

    public function current(Request $request): ?ConsentChoice
    {
        $raw = $request->cookie(self::COOKIE);

        if (! is_string($raw) || strlen($raw) > 200) {
            return null;
        }

        $data = json_decode($raw, true);

        if (! is_array($data) || array_keys($data) !== ['v', 'a', 'm', 't']
            || ! is_int($data['v']) || ! is_bool($data['a']) || ! is_bool($data['m']) || ! is_int($data['t'])) {
            return null;
        }

        $decidedAt = CarbonImmutable::createFromTimestamp($data['t']);

        if ($data['v'] !== $this->version()
            || $decidedAt->isAfter(now()->addSeconds(self::MAX_FUTURE_SECONDS))
            || $decidedAt->isBefore(now()->subDays($this->lifetimeDays()))) {
            return null;
        }

        return new ConsentChoice($data['a'], $data['m'], $data['v'], $decidedAt);
    }

    /**
     * Google consent defaults for this request: denied unless a valid choice grants them.
     *
     * @return array{analytics_storage: string, ad_storage: string, ad_user_data: string, ad_personalization: string}
     */
    public function googleConsentState(Request $request): array
    {
        return $this->current($request)?->googleConsentState() ?? ConsentChoice::deniedGoogleConsentState();
    }

    public function choose(bool $analytics, bool $marketing): ConsentChoice
    {
        return new ConsentChoice($analytics, $marketing, $this->version(), CarbonImmutable::now());
    }

    /**
     * The cookie storing $choice. Laravel's EncryptCookies middleware encrypts it on the way out.
     */
    public function cookie(ConsentChoice $choice, Request $request): Cookie
    {
        $value = json_encode(['v' => $choice->version, 'a' => $choice->analytics, 'm' => $choice->marketing, 't' => $choice->decidedAt->getTimestamp()]);

        return cookie(
            self::COOKIE,
            $value,
            $this->lifetimeDays() * 24 * 60,
            '/',
            config('session.domain'),
            config('session.secure') ?? $request->isSecure(),
            true,
            false,
            'lax',
        );
    }

    /**
     * Expire the browser cookies of every tool the shopper did not (or no
     * longer) consent to. This only removes cookies from this browser; it
     * does not delete anything Google or Meta already collected.
     *
     * @return list<Cookie>
     */
    public function expiredTrackingCookies(ConsentChoice $choice, Request $request): array
    {
        $patterns = array_filter([
            $choice->analytics ? null : self::ANALYTICS_COOKIES,
            $choice->marketing ? null : self::MARKETING_COOKIES,
        ]);
        $names = array_filter(array_keys($request->cookies->all()), function (string $name) use ($patterns): bool {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $name) === 1) {
                    return true;
                }
            }

            return false;
        });

        $cookies = [];

        foreach ($names as $name) {
            // Tags usually set these on the site's top-level domain (".shop.example"), so expire every likely scope.
            foreach ($this->cookieDomains($request->getHost()) as $domain) {
                $cookies[] = cookie()->forget($name, '/', $domain);
            }
        }

        return $cookies;
    }

    /**
     * @return list<string|null>
     */
    private function cookieDomains(string $host): array
    {
        $domains = [null];

        if (filter_var($host, FILTER_VALIDATE_IP) === false && str_contains($host, '.')) {
            $domains[] = '.'.$host;

            if (str_starts_with($host, 'www.')) {
                $domains[] = '.'.substr($host, 4);
            }
        }

        return array_values(array_unique($domains, SORT_REGULAR));
    }

    private function version(): int
    {
        return (int) config('storefront.consent.version', 1);
    }

    private function lifetimeDays(): int
    {
        return max(1, (int) config('storefront.consent.lifetime_days', 180));
    }
}
