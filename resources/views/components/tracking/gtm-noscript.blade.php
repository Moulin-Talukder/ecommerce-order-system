{{-- Google Tag Manager (noscript): immediately after the opening <body> tag. --}}
@php($tagManager = app(App\Services\Tracking\TagManager::class))
@if ($tagManager->enabled())
    <!-- Google Tag Manager (noscript) -->
    <noscript><iframe src="https://www.googletagmanager.com/ns.html?id={{ urlencode($tagManager->containerId()) }}"
    height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
    <!-- End Google Tag Manager (noscript) -->
@endif
