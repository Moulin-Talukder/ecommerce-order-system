<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-gray-100">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">

        <title>{{ isset($title) ? $title.' · ' : '' }}{{ config('app.name') }} Admin</title>

        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
    </head>
    <body class="h-full font-sans text-gray-900 antialiased" x-data="{ sidebarOpen: false }">
        {{-- Mobile sidebar backdrop --}}
        <div
            x-show="sidebarOpen"
            x-cloak
            x-on:click="sidebarOpen = false"
            class="fixed inset-0 z-30 bg-gray-900/50 lg:hidden"
        ></div>

        {{-- Sidebar --}}
        <aside
            class="fixed inset-y-0 left-0 z-40 flex w-64 -translate-x-full flex-col bg-gray-900 text-gray-300 transition-transform lg:translate-x-0"
            x-bind:class="{ 'translate-x-0': sidebarOpen, '-translate-x-full': ! sidebarOpen }"
        >
            <div class="flex h-16 shrink-0 items-center px-6">
                <a href="{{ route('admin.dashboard') }}" class="truncate text-lg font-semibold text-white">
                    {{ config('app.name') }}
                </a>
            </div>

            <nav class="flex-1 space-y-1 overflow-y-auto px-3 py-4" aria-label="Admin navigation">
                <a
                    href="{{ route('admin.dashboard') }}"
                    wire:navigate
                    @class([
                        'flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium',
                        'bg-gray-800 text-white' => request()->routeIs('admin.dashboard'),
                        'hover:bg-gray-800 hover:text-white' => ! request()->routeIs('admin.dashboard'),
                    ])
                >
                    <svg class="size-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955a1.126 1.126 0 0 1 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" />
                    </svg>
                    Dashboard
                </a>
            </nav>
        </aside>

        <div class="flex min-h-full flex-col lg:pl-64">
            {{-- Topbar --}}
            <header class="sticky top-0 z-20 flex h-16 shrink-0 items-center gap-4 border-b border-gray-200 bg-white px-4 sm:px-6">
                <button
                    type="button"
                    class="-m-2 p-2 text-gray-600 lg:hidden"
                    x-on:click="sidebarOpen = true"
                >
                    <span class="sr-only">Open sidebar</span>
                    <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                    </svg>
                </button>

                <div class="flex flex-1 items-center justify-end gap-4">
                    <div class="text-right text-sm leading-tight">
                        <div class="font-medium text-gray-900">{{ auth()->user()->name }}</div>
                        <div class="text-xs text-gray-500">{{ auth()->user()->role->label() }}</div>
                    </div>

                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button
                            type="submit"
                            class="rounded-md border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50"
                        >
                            Log out
                        </button>
                    </form>
                </div>
            </header>

            <main class="flex-1 p-4 sm:p-6 lg:p-8">
                {{ $slot }}
            </main>
        </div>

        @livewireScripts
    </body>
</html>
