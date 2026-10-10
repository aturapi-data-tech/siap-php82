<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>{{ config('app.name', 'Laravel') }}</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    {{-- Display board (TV lobi) pakai font brand Source Sans 3 + JetBrains Mono (angka) --}}
    <link
        href="https://fonts.bunny.net/css?family=source-sans-3:400,500,600,700|jetbrains-mono:400,500&display=swap"
        rel="stylesheet" />

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>

{{-- Display PUBLIK fullscreen — tanpa app shell/topbar, selalu mode terang (TV) --}}
<body class="bg-canvas">

    {{ $slot }}

    @livewireScripts
</body>

</html>
