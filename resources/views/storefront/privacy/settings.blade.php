<x-layouts::storefront title="Privacy settings">
    <div class="mx-auto max-w-2xl">
        <h1 class="text-2xl font-semibold">Privacy settings <span lang="bn" class="block text-lg font-normal text-gray-500">গোপনীয়তা সেটিংস</span></h1>

        <section class="mt-4 rounded-lg border border-gray-200 bg-white p-4 text-sm" aria-labelledby="current-choice">
            <h2 id="current-choice" class="font-semibold">Your current choice</h2>
            @if ($choice)
                <p class="mt-1 text-gray-700">
                    Analytics: <strong>{{ $choice->analytics ? 'on' : 'off' }}</strong> ·
                    Marketing: <strong>{{ $choice->marketing ? 'on' : 'off' }}</strong>
                    <span class="text-gray-500">(saved {{ $choice->decidedAt->timezone(config('app.timezone'))->format('j M Y') }})</span>
                </p>
            @else
                <p class="mt-1 text-gray-700">You have not made a choice yet, so analytics and marketing are <strong>off</strong>.</p>
            @endif
            @unless ($trackingEnabled)
                <p class="mt-2 text-gray-500">Analytics and marketing tools are not active on this site at the moment. Your choice will apply if they are switched on.</p>
            @endunless
        </section>

        <section class="mt-4 rounded-lg border border-gray-200 bg-white p-4" aria-labelledby="change-choice">
            <h2 id="change-choice" class="font-semibold">Change your choice</h2>
            @if ($errors->consent->any())
                <p role="alert" class="mt-2 text-sm text-red-600">Please choose one of the options below.</p>
            @endif
            <x-storefront.consent-form :choice="$choice" id-prefix="consent-settings" class="mt-3" />
            <p class="mt-3 text-sm text-gray-600">
                To withdraw consent, untick a category and save, or choose <strong>Reject all</strong>. Its cookies are then removed from this browser.
                Removing cookies does not delete information that Google or Meta have already received; see the
                <a href="{{ route('storefront.privacy') }}" class="underline">privacy notice</a> for how to ask about that.
            </p>
        </section>
    </div>
</x-layouts::storefront>
