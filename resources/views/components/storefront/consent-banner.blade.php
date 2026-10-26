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
        <div class="mx-auto max-h-[80vh] max-w-6xl overflow-y-auto px-4 py-4">
            <h2 id="consent-banner-title" class="font-semibold">Your privacy choices <span lang="bn" class="font-normal text-gray-500">· আপনার গোপনীয়তা পছন্দ</span></h2>
            <p class="mt-1 text-sm text-gray-600">
                We use necessary cookies to run the shop. With your permission we would also use analytics, and later marketing, tools.
                Nothing optional is used until you choose. You can change your mind at any time in
                <a href="{{ route('storefront.privacy.settings') }}" class="underline">Privacy settings</a>. Read the
                <a href="{{ route('storefront.privacy') }}" class="underline">privacy notice</a>.
            </p>
            <x-storefront.consent-form id-prefix="consent-banner" class="mt-3" />
        </div>
    </section>
@endif
