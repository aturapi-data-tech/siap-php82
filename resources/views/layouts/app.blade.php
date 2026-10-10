<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'Laravel') }}</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    {{-- v2: Source Sans 3 (sans, keterbacaan lansia) + JetBrains Mono (angka/.input-num) + Cormorant Garamond (serif headline) --}}
    <link
        href="https://fonts.bunny.net/css?family=source-sans-3:400,500,600,700|jetbrains-mono:400,500&display=swap"
        rel="stylesheet" />

    <style>
        [x-cloak] {
            display: none !important;
        }
    </style>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>

<body class="bg-canvas dark:bg-gray-900">
    {{-- ✅ x-cloak harus jadi attribute, bukan di event --}}
    <div x-data="layoutDrawer()" x-init="init()" x-cloak x-on:keydown.escape.window="closeAll()">

        {{-- TOP BAR (harus paling atas) --}}
        <header
            class="fixed inset-x-0 top-0 z-50 bg-surface-soft border-b border-hairline dark:bg-gray-800 dark:border-gray-700">
            {{-- Padding kiri-kanan disamakan dgn konten halaman (px-6) supaya tepi topbar & isi sejajar --}}
            <div class="flex items-center justify-between h-20 gap-4 px-4 lg:px-6">

                {{-- LEFT: sidebar hamburger + logo + judul (min-w-0 → judul panjang terpotong, tidak mendesak kanan) --}}
                <div class="flex items-center flex-1 min-w-0 gap-3">
                    @auth
                        {{-- Toggle Sidebar (LOGIN MODE) --}}
                        <button type="button"
                            class="inline-flex items-center justify-center w-10 h-10 rounded-lg shrink-0 hover:bg-surface-soft dark:hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-brand-green/30 dark:focus:ring-brand-lime/30"
                            x-on:click="sidebarOpen = !sidebarOpen" :aria-expanded="sidebarOpen.toString()">
                            <span class="sr-only">Toggle sidebar</span>

                            {{-- ICON: Open --}}
                            <svg x-show="!sidebarOpen" x-cloak class="w-6 h-6 text-muted dark:text-gray-300"
                                fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" clip-rule="evenodd"
                                    d="M2 4.75A.75.75 0 012.75 4h14.5a.75.75 0 010 1.5H2.75A.75.75 0 012 4.75zm0 5A.75.75 0 012.75 9h14.5a.75.75 0 010 1.5H2.75A.75.75 0 012 9.75zm0 5a.75.75 0 01.75-.75h14.5a.75.75 0 010 1.5H2.75A.75.75 0 012 14.75z" />
                            </svg>

                            {{-- ICON: Close --}}
                            <svg x-show="sidebarOpen" x-cloak class="w-6 h-6 text-muted dark:text-gray-300"
                                fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" clip-rule="evenodd"
                                    d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" />
                            </svg>
                        </button>
                    @endauth


                    <a wire:navigate href="{{ url('/') }}" class="flex items-center gap-3 shrink-0">
                        {{-- Logo & nama dari Sistem › Identitas Instansi --}}
                        @php
                            $logoTopbar = \App\Support\Identitas::logoUrl();
                            $logoIkon = \App\Support\Identitas::logoIkonUrl();
                        @endphp
                        @if ($logoTopbar)
                            {{-- HP: ikon saja (bila tersedia); sm ke atas: logo lengkap --}}
                            @if ($logoIkon)
                                <img src="{{ $logoIkon }}" alt="{{ \App\Support\Identitas::get()->nama }}"
                                    class="object-contain w-auto h-[25px] rounded sm:hidden dark:bg-white/90">
                            @endif
                            <img src="{{ $logoTopbar }}" alt="{{ \App\Support\Identitas::get()->nama }}"
                                class="{{ $logoIkon ? 'hidden sm:block' : 'max-w-[40vw] sm:max-w-none' }} object-contain w-auto h-[25px] rounded dark:bg-white/90">
                        @else
                            {{-- Nama hanya tampil bila belum ada logo (logo sudah memuat nama) --}}
                            <span class="hidden text-base font-bold leading-tight sm:block text-brand-green dark:text-brand-lime max-w-[12rem]">
                                {{ \App\Support\Identitas::namaPendek() }}
                            </span>
                        @endif
                    </a>

                    {{-- Page title — di-set per-page via x-init $store.pageTitle = {...} --}}
                    <div x-data x-show="$store.pageTitle?.title" x-cloak
                        class="hidden min-w-0 pl-3 leading-tight border-l lg:flex flex-col justify-center border-hairline dark:border-gray-700">
                        <span class="text-xl font-semibold leading-tight truncate text-ink dark:text-gray-100"
                            x-text="$store.pageTitle.title" :title="$store.pageTitle.title"></span>
                        <span class="mt-0.5 text-sm truncate text-muted dark:text-gray-400"
                            x-show="$store.pageTitle.subtitle"
                            x-text="$store.pageTitle.subtitle" :title="$store.pageTitle.subtitle"></span>
                    </div>
                </div>

                {{-- RIGHT --}}
                @include('layouts.app-topbar')
            </div>

        </header>

        {{-- ✅ OVERLAY mulai dari bawah header (top-20), jadi topbar tetap putih --}}
        <div x-cloak x-show="sidebarOpen" x-transition.opacity
            class="fixed inset-x-0 bottom-0 z-40 top-20 bg-gray-900/50" x-on:click="sidebarOpen = false"></div>

        {{-- ✅ SIDEBAR mulai dari bawah header (top-20), bukan mt-20 --}}
        @include('layouts.app-sidebar')

        <div class="pt-20">
            {{-- HEADER SLOT --}}
            @isset($header)
                <header class="bg-canvas shadow dark:bg-gray-800">
                    <div class="w-full px-4 py-2 sm:px-6 lg:px-8">
                        {{ $header }}
                    </div>
                </header>
            @endisset

            {{-- PAGE CONTENT --}}
            <main class="min-h-[calc(100vh-5rem)]">
                {{ $slot }}
            </main>
        </div>

    </div>

    <script>
        function layoutDrawer() {
            return {
                sidebarOpen: false,
                openMenus: {},
                menuQuery: '',

                topNavOpen: false,
                topDropdownOpen: false,

                init() {
                    this.openMenus = {}

                    this.$watch('sidebarOpen', (val) => {
                        document.body.classList.toggle('overflow-hidden', val)
                    })
                },

                toggleMenu(key) {
                    this.openMenus[key] = !this.openMenus[key]
                },

                closeAll() {
                    this.sidebarOpen = false
                    this.topNavOpen = false
                    this.topDropdownOpen = false
                },
            }
        }
    </script>

    <script>
        // Page title untuk topbar — di-set per-page via x-init.
        // Direset saat wire:navigate sebelum swap, supaya page yang tidak set title
        // tidak warisin title dari page sebelumnya.
        document.addEventListener('alpine:init', () => {
            Alpine.store('pageTitle', { title: '', subtitle: '' });
        });
        document.addEventListener('livewire:navigating', () => {
            const s = window.Alpine?.store('pageTitle');
            if (s) { s.title = ''; s.subtitle = ''; }
        });
    </script>

    <script>
        document.addEventListener('livewire:init', () => {

            if (window.toastr) {
                toastr.options = {
                    closeButton: true,
                    progressBar: true,
                    positionClass: 'toast-top-left', // 🔥 kiri atas
                    timeOut: 4000, // 4 detik
                    extendedTimeOut: 1500,
                    showDuration: 300,
                    hideDuration: 300,
                    showEasing: 'swing',
                    hideEasing: 'linear',
                    showMethod: 'fadeIn',
                    hideMethod: 'fadeOut',
                    newestOnTop: true,
                    preventDuplicates: true,
                };
            }

            Livewire.on('toast', ({
                type,
                message,
                opts
            }) => {
                if (window.toastr) {
                    toastr[type]?.(message, '', opts || {}) ?? toastr.info(message, '', opts || {});
                } else {
                    console.log(`[${type}] ${message}`);
                }
            });
        });
    </script>



    @livewireScripts
</body>

</html>
