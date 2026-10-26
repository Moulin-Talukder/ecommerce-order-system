{{--
    Shown only while tracking (GTM) is enabled and the shopper has no valid,
    current consent choice. Not shown on the privacy settings page, which
    carries the same form.
--}}
@php($showConsentBanner = app(App\Services\Tracking\TagManager::class)->enabled()
    && app(App\Services\Tracking\Consent::class)->current(request()) === null
    && ! request()->routeIs('storefront.privacy.settings'))
@if ($showConsentBanner)
    <section aria-labelledby="consent-banner-title" class="sticky bottom-0 z-40 border-t border-gray-300 bg-white shadow-lg">
        <div class="mx-auto max-h-[60vh] max-w-6xl overflow-y-auto px-4 py-3">
            <h2 id="consent-banner-title" class="text-sm font-semibold">Your privacy choices <span lang="bn" class="font-normal text-gray-500">· আপনার গোপনীয়তা পছন্দ</span></h2>
            <p class="mt-1 text-sm text-gray-600">
                We use necessary cookies to run the shop. Analytics and marketing stay off unless you allow them.
                Change this any time in <a href="{{ route('storefront.privacy.settings') }}" class="underline">Privacy settings</a> ·
                <a href="{{ route('storefront.privacy') }}" class="underline">Privacy notice</a>.
            </p>
            <x-storefront.consent-form id-prefix="consent-banner" compact class="mt-2" />
        </div>
    </section>
@endif
