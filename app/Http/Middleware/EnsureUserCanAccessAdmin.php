<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ensures the authenticated user is still allowed to use the admin panel.
 *
 * Runs on every admin request (after `auth`), so a user who is deactivated
 * while signed in loses access on their next request instead of when their
 * session expires.
 */
class EnsureUserCanAccessAdmin
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->canAccessAdmin()) {
            return $next($request);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            abort(Response::HTTP_FORBIDDEN, __('Your account does not have access to the admin panel.'));
        }

        return redirect()->route('login')->withErrors([
            'email' => __('Your account has been deactivated. Please contact an administrator.'),
        ]);
    }
}
