<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
    @class(['dark' => request()->cookie('theme_resolved') === 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        @include('partials.theme-script')
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

        @include('partials.fonts')

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body>
        <div class="min-h-screen flex flex-col items-center justify-center px-6 py-12 bg-page">
            <x-wordmark class="text-5xl" />

            <p class="mt-4 max-w-md text-center text-base text-ink-dim">
                Drafted by AI, approved by you, sent from your own mailboxes.
            </p>

            @if (Route::has('login'))
                <div class="mt-8">
                    <livewire:welcome.navigation />
                </div>
            @endif
        </div>
    </body>
</html>
