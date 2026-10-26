<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\RedirectResponse;

/**
 * Redirects to a form-supplied return path only when it is a same-site
 * relative path ("/..."): absolute and protocol-relative URLs, backslashes,
 * whitespace and non-strings fall back to back(), so no open redirect.
 *
 * Storefront forms post their own path because search-result pages send
 * only their origin as Referer ("Referrer-Policy: origin").
 */
trait RedirectsToSafePath
{
    protected function redirectToSafePath(mixed $path): RedirectResponse
    {
        if (is_string($path) && preg_match('#^/(?![/\\\\])[^\s\\\\]*$#', $path) === 1) {
            return redirect()->to(url($path));
        }

        return back();
    }
}
