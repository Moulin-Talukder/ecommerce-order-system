{{--
    Google Tag Manager container (storefront only). Placement per Google's
    "Install a web container" guide: as high in <head> as possible, below the
    dataLayer declaration. Rendered only when a valid GTM_CONTAINER_ID is set.

    The privacy-safe page_location / page_referrer (no search text) are pushed
    before the container loads, for the Google tag's configuration settings.

    Consent defaults come first, rendered by the server from the shopper's
    stored choice (denied without a valid one), so no tag can run before the
    choice is known. gtag() here only queues consent commands for GTM; no
    gtag.js library is loaded.
--}}
@php($tagManager = app(App\Services\Tracking\TagManager::class))
@if ($tagManager->enabled())
    @php($consentState = app(App\Services\Tracking\Consent::class)->googleConsentState(request()))
    <script @if ($nonce = Illuminate\Support\Facades\Vite::cspNonce()) nonce="{{ $nonce }}" @endif>window.dataLayer = window.dataLayer || [];
    function gtag(){window.dataLayer.push(arguments);}
    gtag('consent', 'default', {{ Illuminate\Support\Js::from($consentState) }});
    gtag('set', 'ads_data_redaction', {{ $consentState['ad_storage'] === 'denied' ? 'true' : 'false' }});
    window.dataLayer.push({{ Illuminate\Support\Js::from(app(App\Services\Tracking\PageContext::class)->forRequest(request())) }});</script>
    <!-- Google Tag Manager -->
    <script @if ($nonce) nonce="{{ $nonce }}" @endif>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
    new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
    j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
    'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
    })(window,document,'script','dataLayer',{{ Illuminate\Support\Js::from($tagManager->containerId()) }});</script>
    <!-- End Google Tag Manager -->
@endif
