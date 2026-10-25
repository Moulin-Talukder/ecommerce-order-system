{{--
    Google Tag Manager container (storefront only). Placement per Google's
    "Install a web container" guide: as high in <head> as possible, below the
    dataLayer declaration. Rendered only when a valid GTM_CONTAINER_ID is set.

    The privacy-safe page_location / page_referrer (no search text) are pushed
    before the container loads, for the Google tag's configuration settings.
--}}
@php($tagManager = app(App\Services\Tracking\TagManager::class))
@if ($tagManager->enabled())
    <script @if ($nonce = Illuminate\Support\Facades\Vite::cspNonce()) nonce="{{ $nonce }}" @endif>window.dataLayer = window.dataLayer || [];
    window.dataLayer.push({{ Illuminate\Support\Js::from(app(App\Services\Tracking\PageContext::class)->forRequest(request())) }});</script>
    <!-- Google Tag Manager -->
    <script @if ($nonce) nonce="{{ $nonce }}" @endif>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
    new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
    j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
    'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
    })(window,document,'script','dataLayer',{{ Illuminate\Support\Js::from($tagManager->containerId()) }});</script>
    <!-- End Google Tag Manager -->
@endif
