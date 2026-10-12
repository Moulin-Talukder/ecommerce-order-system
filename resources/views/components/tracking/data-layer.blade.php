{{--
    Pushes this response's server-built ecommerce events, and registers one
    listener for events confirmed by Livewire cart actions ("datalayer-push").
    Must render before @livewireScripts so the livewire:init listener exists
    when Livewire starts. Every push is wrapped in try/catch: tracking can
    never break the page.
--}}
@php($tagManager = app(App\Services\Tracking\TagManager::class))
@if ($tagManager->enabled())
    <script @if ($nonce = Illuminate\Support\Facades\Vite::cspNonce()) nonce="{{ $nonce }}" @endif>
        (function () {
            var push = function (event) {
                try {
                    window.dataLayer = window.dataLayer || [];
                    window.dataLayer.push({ ecommerce: null });
                    window.dataLayer.push(event);
                } catch (error) {}
            };

            {{ Illuminate\Support\Js::from(app(App\Services\Tracking\DataLayer::class)->events()) }}.forEach(push);

            if (! window.__storefrontDataLayerListener) {
                window.__storefrontDataLayerListener = true;
                document.addEventListener('livewire:init', function () {
                    try {
                        window.Livewire.on('datalayer-push', function (params) {
                            ((params && params.events) || []).forEach(push);
                        });
                    } catch (error) {}
                });
            }
        })();
    </script>
@endif
