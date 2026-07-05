<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"  @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script>
            (function() {
                const appearance = '{{ $appearance ?? "system" }}';

                if (appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }
            })();
        </script>

        {{-- Inline style to set the HTML background color based on our theme in app.css --}}
        <style>
            html {
                background-color: oklch(1 0 0);
            }

            html.dark {
                background-color: oklch(0.145 0 0);
            }
        </style>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        <meta name="description" content="Open-source, self-hosted transactional email for Laravel, powered by your own Amazon SES. Dashboard, API keys, delivery logs, webhooks, and suppressions on your infrastructure.">
        <meta property="og:type" content="website">
        <meta property="og:site_name" content="Larasend">
        <meta property="og:title" content="Larasend — The email dashboard AWS never built">
        <meta property="og:description" content="Open-source, self-hosted transactional email for Laravel, powered by your own Amazon SES. Dashboard, API keys, delivery logs, webhooks, and suppressions on your infrastructure.">
        <meta property="og:url" content="{{ url('/') }}">
        <meta property="og:image" content="{{ url('/og.png') }}">
        <meta property="og:image:width" content="1200">
        <meta property="og:image:height" content="630">
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:title" content="Larasend — The email dashboard AWS never built">
        <meta name="twitter:description" content="Open-source, self-hosted transactional email for Laravel, powered by your own Amazon SES. Dashboard, API keys, delivery logs, webhooks, and suppressions on your infrastructure.">
        <meta name="twitter:image" content="{{ url('/og.png') }}">

        @fonts

        @vite(['resources/css/app.css', 'resources/js/app.ts', "resources/js/pages/{$page['component']}.vue"])
        <x-inertia::head>
            <title>{{ config('app.name', 'Larasend') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
