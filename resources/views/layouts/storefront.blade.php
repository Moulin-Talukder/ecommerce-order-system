<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-gray-50">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ isset($title) && $title ? $title.' · ' : '' }}{{ config('app.name') }}</title>
        @isset($metaDescription)
            <meta name="description" content="{{ $metaDescription }}">
        @endisset

        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
    </head>
    <body class="flex min-h-full flex-col font-sans text-gray-900 antialiased">
        <header class="border-b border-gray-200 bg-white">
            <div class="mx-auto flex max-w-6xl flex-wrap items-center gap-4 px-4 py-3">
                <a href="{{ route('storefront.home') }}" class="text-lg font-semibold">{{ config('app.name') }}</a>

                <form method="GET" action="{{ route('storefront.home') }}" class="order-3 w-full sm:order-none sm:ml-4 sm:w-auto sm:flex-1" role="search">
                    <label for="q" class="sr-only">Search products</label>
                    <input id="q" name="q" type="search" value="{{ request('q') }}" placeholder="Search products…" maxlength="100"
                        class="block w-full rounded-full border border-gray-300 px-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-gray-900">
                </form>

                <a href="{{ route('storefront.cart') }}" class="ml-auto inline-flex items-center gap-2 rounded-full border border-gray-300 px-4 py-2 text-sm font-medium hover:bg-gray-50">
                    <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 0 0-3 3h15.75m-12.75-3h11.218c1.121-2.3 2.1-4.684 2.924-7.138a60.114 60.114 0 0 0-16.536-1.84M7.5 14.25 5.106 5.272M6 20.25a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Zm12.75 0a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Z" /></svg>
                    Cart <livewire:storefront.cart-count />
                </a>
            </div>

            @php($navCategories = app(App\Services\Storefront\Catalog::class)->navigationCategories())
            @if ($navCategories)
                <nav class="mx-auto max-w-6xl overflow-x-auto px-4 pb-3" aria-label="Categories">
                    <ul class="flex gap-2 text-sm whitespace-nowrap">
                        @foreach ($navCategories as $navCategory)
                            <li><a href="{{ route('storefront.category', $navCategory->slug) }}" class="rounded-full bg-gray-100 px-3 py-1 text-gray-700 hover:bg-gray-200">{{ $navCategory->name }}</a></li>
                        @endforeach
                    </ul>
                </nav>
            @endif
        </header>

        <main class="mx-auto w-full max-w-6xl flex-1 px-4 py-6">
            @if (session('success'))
                <div role="status" class="mb-4 rounded-md border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">{{ session('success') }}</div>
            @endif
            @if (session('error'))
                <div role="alert" class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ session('error') }}</div>
            @endif

            {{ $slot }}
        </main>

        <footer class="border-t border-gray-200 bg-white">
            <div class="mx-auto max-w-6xl px-4 py-6 text-sm text-gray-500">
                © {{ now()->year }} {{ config('app.name') }} · Cash on Delivery across Bangladesh
            </div>
        </footer>

        @livewireScripts
    </body>
</html>
