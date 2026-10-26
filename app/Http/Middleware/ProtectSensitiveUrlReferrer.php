<?php

namespace App\Http\Middleware;

use App\Services\Tracking\PageContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Storefront pages whose URL carries search text (?q=) or a value that looks
 * like an email or phone number are served with "Referrer-Policy: origin", so
 * the next page, and any script reading document.referrer there (analytics or
 * advertising tags such as the Meta Pixel), only sees the site origin.
 * Applies whether or not GTM is enabled.
 */
class ProtectSensitiveUrlReferrer
{
    public function __construct(private PageContext $pageContext) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($this->pageContext->hasSensitiveUrl($request)) {
            $response->headers->set('Referrer-Policy', 'origin');
        }

        return $response;
    }
}
