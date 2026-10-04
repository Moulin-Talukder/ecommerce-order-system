<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-gray-100">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">

        <title>{{ isset($title) ? $title.' · ' : '' }}{{ config('app.name') }}</title>

        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="flex min-h-full items-center justify-center px-4 py-12 font-sans text-gray-900 antialiased">
        <div class="w-full max-w-sm">
            <div class="mb-8 text-center text-2xl font-semibold">{{ config('app.name') }}</div>

            {{ $slot }}
        </div>
    </body>
</html>
