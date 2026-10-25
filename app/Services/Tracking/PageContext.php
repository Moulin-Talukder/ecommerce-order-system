<?php

namespace App\Services\Tracking;

use Illuminate\Http\Request;

/**
 * Privacy-safe page_location / page_referrer for analytics tags.
 *
 * Google tags read the browser URL by default, which would send storefront
 * search text (?q=, free text that may hold a phone number, email or name)
 * to Google. The GTM Google tag is configured to use these values instead:
 *  - shopper-typed parameters (q) are removed;
 *  - any other parameter value that looks like an email or phone number is
 *    replaced with "redacted" (e.g. from a tagged marketing link);
 *  - credentials and fragments are dropped.
 * Marketing parameters (utm_*, gclid, ...) and pagination are kept.
 */
class PageContext
{
    /** Query parameters whose values are typed by shoppers. */
    private const FREE_TEXT_PARAMETERS = ['q'];

    private const REDACTED = 'redacted';

    /**
     * @return array{page_location: string, page_referrer: string|null}
     */
    public function forRequest(Request $request): array
    {
        return [
            'page_location' => $this->sanitize($request->getSchemeAndHttpHost().$request->getRequestUri()),
            'page_referrer' => $this->sanitize((string) $request->headers->get('referer')),
        ];
    }

    public function sanitize(string $url): ?string
    {
        $parts = parse_url(trim($url));

        if ($parts === false || ! isset($parts['scheme'], $parts['host']) || ! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return null;
        }

        $location = strtolower($parts['scheme']).'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '').($parts['path'] ?? '/');
        $query = $this->sanitizeQuery($parts['query'] ?? '');

        return $query === '' ? $location : $location.'?'.$query;
    }

    private function sanitizeQuery(string $query): string
    {
        $pairs = [];

        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }

            [$name, $value] = array_pad(explode('=', $pair, 2), 2, null);
            $decodedName = strtolower(preg_replace('/\[.*$/', '', urldecode($name)));

            if (in_array($decodedName, self::FREE_TEXT_PARAMETERS, true) || ($value === null && $this->looksPersonal(urldecode($name)))) {
                continue;
            }

            $pairs[] = $value !== null && $this->looksPersonal(urldecode($value)) ? $name.'='.self::REDACTED : $pair;
        }

        return implode('&', $pairs);
    }

    private function looksPersonal(string $value): bool
    {
        return str_contains($value, '@')
            || preg_match('/^[\s()+\-.]*(?:\d[\s()+\-.]*){7,}$/', $value) === 1;
    }
}
