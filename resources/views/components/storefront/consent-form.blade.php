@props(['choice' => null, 'idPrefix' => 'consent'])
{{--
    Tracking-consent form shared by the banner and the privacy settings page.
    Works without JavaScript. Optional categories are never pre-ticked for a
    new shopper; Accept, Reject and Save carry equal visual weight.
--}}
<form method="POST" action="{{ route('storefront.privacy.consent') }}" {{ $attributes }}>
    @csrf
    <input type="hidden" name="return_to" value="{{ request()->getRequestUri() }}">

    <fieldset class="space-y-3">
        <legend class="sr-only">Cookie and tracking choices</legend>

        <div class="rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-sm">
            <p class="font-medium text-gray-900">Necessary <span class="font-normal text-gray-500">— always on</span></p>
            <p class="mt-0.5 text-gray-600">Keep your cart, checkout and security working. They are not used for analytics or advertising.</p>
        </div>

        <label for="{{ $idPrefix }}-analytics" class="flex cursor-pointer items-start gap-3 rounded-md border border-gray-300 px-3 py-2 text-sm has-[:checked]:border-gray-900">
            <input id="{{ $idPrefix }}-analytics" type="checkbox" name="analytics" value="1" class="mt-1" @checked($choice?->analytics)>
            <span>
                <span class="font-medium text-gray-900">Analytics</span>
                <span class="block text-gray-600">Google Analytics helps us understand which pages and products are used. Off unless you turn it on.</span>
            </span>
        </label>

        <label for="{{ $idPrefix }}-marketing" class="flex cursor-pointer items-start gap-3 rounded-md border border-gray-300 px-3 py-2 text-sm has-[:checked]:border-gray-900">
            <input id="{{ $idPrefix }}-marketing" type="checkbox" name="marketing" value="1" class="mt-1" @checked($choice?->marketing)>
            <span>
                <span class="font-medium text-gray-900">Marketing</span>
                <span class="block text-gray-600">Lets advertising tools measure our ads. No advertising tool is active on this site yet. Off unless you turn it on.</span>
            </span>
        </label>
    </fieldset>

    <div class="mt-4 grid gap-2 sm:grid-cols-3">
        <button type="submit" name="decision" value="reject" class="rounded-md border border-gray-900 bg-white px-3 py-2 text-sm font-medium text-gray-900 hover:bg-gray-100">Reject all</button>
        <button type="submit" name="decision" value="accept" class="rounded-md border border-gray-900 bg-white px-3 py-2 text-sm font-medium text-gray-900 hover:bg-gray-100">Accept all</button>
        <button type="submit" name="decision" value="save" class="rounded-md border border-gray-900 bg-white px-3 py-2 text-sm font-medium text-gray-900 hover:bg-gray-100">Save my choices</button>
    </div>
</form>
