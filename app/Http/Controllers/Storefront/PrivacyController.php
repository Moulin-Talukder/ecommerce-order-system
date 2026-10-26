<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Concerns\RedirectsToSafePath;
use App\Http\Controllers\Controller;
use App\Services\Tracking\Consent;
use App\Services\Tracking\TagManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

/**
 * Privacy notice, privacy settings and the tracking-consent form (banner and
 * settings page post to the same endpoint, and work without JavaScript).
 */
class PrivacyController extends Controller
{
    use RedirectsToSafePath;

    public function notice(): View
    {
        return view('storefront.privacy.notice', ['details' => config('storefront.privacy')]);
    }

    public function settings(Request $request, Consent $consent, TagManager $tagManager): View
    {
        return view('storefront.privacy.settings', [
            'choice' => $consent->current($request),
            'trackingEnabled' => $tagManager->enabled(),
        ]);
    }

    /**
     * "accept" and "reject" set both optional categories; "save" stores the
     * ticked boxes. Withdrawing (a category now denied) also expires that
     * category's cookies in this browser.
     */
    public function update(Request $request, Consent $consent): RedirectResponse
    {
        $validator = Validator::make($request->all(), [
            'decision' => ['required', 'string', 'in:accept,reject,save'],
            'analytics' => ['nullable', 'string', 'in:1'],
            'marketing' => ['nullable', 'string', 'in:1'],
            'return_to' => ['nullable', 'string', 'max:2048'],
        ]);

        $returnTo = $validator->errors()->has('return_to') ? null : $request->input('return_to');

        if ($validator->fails()) {
            return $this->redirectToSafePath($returnTo)
                ->withErrors($validator, 'consent')
                ->with('error', 'Your privacy choices could not be saved. Please try again.');
        }

        $decision = $validator->validated()['decision'];
        $choice = match ($decision) {
            'accept' => $consent->choose(analytics: true, marketing: true),
            'reject' => $consent->choose(analytics: false, marketing: false),
            'save' => $consent->choose(analytics: $request->input('analytics') === '1', marketing: $request->input('marketing') === '1'),
        };

        return $this->redirectToSafePath($returnTo)
            ->withCookie($consent->cookie($choice, $request))
            ->withCookies($consent->expiredTrackingCookies($choice, $request))
            ->with('success', 'Your privacy choices were saved.');
    }
}
