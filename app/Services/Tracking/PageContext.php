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
 *
 * page_has_search_text and page_has_personal_data_in_url tell GTM to block
 * tags that read the raw browser URL themselves (the Meta Pixel) on such
 * pages; neither the text nor the data is ever sent.
 */
class PageContext
{
    /** Query parameters whose values are typed by shoppers. */
    private const FREE_TEXT_PARAMETERS = ['q'];

    private const REDACTED = 'redacted';

    /**
     * @return array{page_location: string, page_referrer: string|null, page_has_search_text: bool, page_has_personal_data_in_url: bool}
     */
    public function forRequest(Request $request): array
    {
        return [
            'page_location' => $this->sanitize($request->getSchemeAndHttpHost().$request->getRequestUri()),
            'page_referrer' => $this->sanitize((string) $request->headers->get('referer')),
            'page_has_search_text' => $this->hasSearchText($request),
            'page_has_personal_data_in_url' => $this->hasPersonalDataInUrl($request),
        ];
    }

    /**
     * Whether the URL holds text that must not reach tags reading the raw
     * browser URL: search text, or a value that looks like an email or phone.
     */
    public function hasSensitiveUrl(Request $request): bool
    {
        return $this->hasSearchText($request) || $this->hasPersonalDataInUrl($request);
    }

    /**
     * Whether any other query parameter looks like an email address or phone
     * number (e.g. from a tagged marketing link).
     */
    public function hasPersonalDataInUrl(Request $request): bool
    {
        foreach ($this->queryPairs($request) as [$name, $value]) {
            if (! $this->isFreeText($name) && $this->pairLooksPersonal($name, $value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the page URL carries shopper-typed text (a non-blank ?q=).
     */
    public function hasSearchText(Request $request): bool
    {
        foreach ($this->queryPairs($request) as [$name, $value]) {
            if ($this->isFreeText($name) && trim(urldecode($value ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Raw name/value pairs of the request query string (value null for "?name").
     *
     * @return list<array{string, string|null}>
     */
    private function queryPairs(Request $request): array
    {
        $pairs = [];

        foreach (explode('&', (string) parse_url($request->getRequestUri(), PHP_URL_QUERY)) as $pair) {
            if ($pair !== '') {
                $pairs[] = array_pad(explode('=', $pair, 2), 2, null);
            }
        }

        return $pairs;
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

            if ($this->isFreeText($name) || $this->looksPersonal(urldecode($name))) {
                continue;
            }

            $pairs[] = $value !== null && $this->looksPersonal(urldecode($value)) ? $name.'='.self::REDACTED : $pair;
        }

        return implode('&', $pairs);
    }

    private function isFreeText(string $rawName): bool
    {
        return in_array(strtolower(preg_replace('/\[.*$/', '', urldecode($rawName))), self::FREE_TEXT_PARAMETERS, true);
    }

    private function pairLooksPersonal(string $rawName, ?string $rawValue): bool
    {
        return $this->looksPersonal(urldecode($rawName)) || ($rawValue !== null && $this->looksPersonal(urldecode($rawValue)));
    }

    /**
     * An email-like value, a value made only of 7+ digits and separators, or a
     * Bangladeshi mobile number standing apart anywhere in the text. Random
     * click IDs (gclid, fbclid) are not matched: the mobile number must not
     * touch other letters or digits.
     */
    private function looksPersonal(string $value): bool
    {
        return str_contains($value, '@')
            || preg_match('/^[\s()+\-.]*(?:\d[\s()+\-.]*){7,}$/', $value) === 1
            || preg_match('/(?<![a-z0-9])(?:\+?880[\s.\-]?|0)1[3-9](?:[\s.\-]?\d){8}(?![a-z0-9])/i', $value) === 1;
    }
}
