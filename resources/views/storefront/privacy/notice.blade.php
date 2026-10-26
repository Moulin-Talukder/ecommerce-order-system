{{--
    DRAFT privacy notice structure. The wording is a placeholder that must be
    reviewed by a Bangladeshi lawyer (Personal Data Protection Act, 2026)
    before production; it does not claim legal compliance. Business details
    come from config('storefront.privacy') and show as placeholders when unset.
--}}
@php($placeholder = fn (?string $value, string $label) => filled($value) ? $value : "[{$label} — to be provided]")
<x-layouts::storefront title="Privacy notice">
    <article class="mx-auto max-w-3xl space-y-6 text-sm leading-6 text-gray-700">
        <header>
            <h1 class="text-2xl font-semibold text-gray-900">Privacy notice <span lang="bn" class="block text-lg font-normal text-gray-500">গোপনীয়তা নীতি</span></h1>
            <p role="note" class="mt-3 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-amber-900">
                Draft — this notice is pending legal review and will be updated before it is final.
                <span lang="bn" class="block">খসড়া — এই নীতিটি আইনি পর্যালোচনার অপেক্ষায় রয়েছে এবং চূড়ান্ত করার আগে হালনাগাদ করা হবে।</span>
            </p>
        </header>

        @php($sections = [
            ['Who we are', 'আমরা কারা', 'Data custodian: '.$placeholder($details['business_name'] ?? null, 'Business name').', '.$placeholder($details['business_address'] ?? null, 'Business address').'. Privacy contact: '.$placeholder($details['contact'] ?? null, 'Privacy contact').'.'],
            ['What we collect', 'আমরা কী তথ্য সংগ্রহ করি', 'For orders: your name, phone and alternate phone, email (optional), delivery address and order notes. With your permission only: cookies and online identifiers used by Google Analytics (Analytics) and the Meta Pixel (Marketing).'],
            ['Why we use it', 'আমরা কেন তথ্য ব্যবহার করি', 'To process and deliver your Cash on Delivery order and contact you about it. Analytics helps us understand how the shop is used; Marketing lets Meta measure our Facebook and Instagram ads. Each is used only if you opt in.'],
            ['What we do not send to Google or Meta', 'যা গুগল বা মেটা-কে পাঠানো হয় না', 'Your name, phone numbers, email, address, order notes and the words you search for.'],
            ['Who receives data', 'কারা তথ্য পায়', 'Steadfast Courier receives the delivery details needed to deliver your order. Google (Google Analytics) receives usage data only if you allow Analytics. Meta Platforms (Meta Pixel) receives data only if you allow Marketing: pages you view (not search results, and not pages whose web address contains personal details), products you view or add to your cart, checkout starts and purchases (product code, price, quantity, order number and total), plus technical data the Pixel collects itself, such as your IP address, browser details and Meta cookies. [How Meta may use this data, including with Facebook or Instagram account information — to be confirmed by legal review.]'],
            ['Transfers outside Bangladesh', 'বাংলাদেশের বাইরে তথ্য স্থানান্তর', 'Google and Meta may process data on servers outside Bangladesh. [Legal basis and safeguards — to be confirmed by legal review.]'],
            ['How long we keep data', 'কতদিন তথ্য সংরক্ষণ করা হয়', 'Order records: [period — to be confirmed]. Google Analytics: [retention setting — to be confirmed]. Your consent choice is remembered for up to '.config('storefront.consent.lifetime_days').' days.'],
            ['Your choices and withdrawal', 'আপনার সম্মতি ও প্রত্যাহার', 'Analytics and Marketing are off until you turn them on. You can change or withdraw your choice at any time in Privacy settings; withdrawing stops those tools on the next page and removes their cookies (Google Analytics: _ga; Meta: _fbp, _fbc) from your browser. It does not delete data Google or Meta already received; contact us to ask about that.'],
            ['Your rights', 'আপনার অধিকার', 'You can ask to see, correct or delete your personal data, or object to its use, by contacting us. [Response times and procedure — to be confirmed by legal review.]'],
            ['Complaints', 'অভিযোগ', 'Please contact us first. You may also complain to the data protection authority. [Authority name and contact — to be confirmed.]'],
            ['Children', 'শিশু', 'This shop is intended for adults. We do not knowingly track, profile or target advertising at anyone under 18.'],
            ['Changes to this notice', 'এই নীতির পরিবর্তন', 'When this notice changes we will update it here and ask for your consent choice again where needed. [Effective date — to be provided.]'],
        ])

        @foreach ($sections as [$english, $bengali, $text])
            <section aria-labelledby="privacy-section-{{ $loop->iteration }}">
                <h2 id="privacy-section-{{ $loop->iteration }}" class="text-base font-semibold text-gray-900">{{ $loop->iteration }}. {{ $english }} <span lang="bn" class="font-normal text-gray-500">· {{ $bengali }}</span></h2>
                <p class="mt-1">{{ $text }}</p>
                <p lang="bn" class="mt-1 text-gray-500">[বাংলা অনুবাদ — আইনি পর্যালোচনার পর যুক্ত করা হবে]</p>
            </section>
        @endforeach

        <p><a href="{{ route('storefront.privacy.settings') }}" class="font-medium underline">Privacy settings · গোপনীয়তা সেটিংস</a></p>
    </article>
</x-layouts::storefront>
