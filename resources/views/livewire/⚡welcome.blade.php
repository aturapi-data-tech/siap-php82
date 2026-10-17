<?php

use Livewire\Component;

new class extends Component {
    // state optional
};
?>

@extends('layouts.app-welcome')

@section('content')
    <div class="relative w-full min-h-[calc(100vh-5rem)] overflow-hidden bg-canvas dark:bg-gray-900">
        {{-- BG Welcome --}}
        <div class="absolute inset-0 z-10 overflow-hidden pointer-events-none">
            <img src="{{ asset('images/Logogram black solid.png') }}" alt=""
                class="absolute -left-[5%] -bottom-[10%] w-[40rem] lg:w-[48rem] opacity-5">
        </div>

        @php
            $identitas = \App\Support\Identitas::get();
            $logoIdentitas = \App\Support\Identitas::logoUrl();
            $slides = [
                asset('images/landing/slide-1.png'),
                asset('images/landing/slide-2.png'),
                asset('images/landing/slide-3.png'),
                asset('images/landing/slide-4.png'),
                asset('images/landing/slide-5.png'),
                asset('images/landing/slide-6.png'),
                asset('images/landing/slide-7.png'),
                asset('images/landing/slide-8.png'),
                asset('images/landing/slide-9.png'),
            ];
        @endphp

        {{-- WRAPPER flex supaya footer nempel bawah --}}
        <section class="relative z-20 flex flex-col min-h-[calc(100vh-5rem)]">
            {{-- HERO --}}
            <div class="flex-1">
                <div class="grid items-center grid-cols-1 gap-10 px-6 py-10 mx-auto max-w-7xl lg:grid-cols-2 lg:py-14">
                    {{-- LEFT --}}
                    <div>
                        <p
                            class="inline-flex items-center gap-2 px-3 py-1.5 text-caption font-semibold uppercase tracking-wide rounded-full ring-1
                                   bg-brand-green/10 text-brand-green ring-brand-green/20
                                   dark:bg-brand-lime/10 dark:text-brand-lime dark:ring-brand-lime/20">
                            <span class="w-2 h-2 rounded-full bg-brand-lime animate-pulse"></span>
                            Sistem Informasi Aset &amp; Pegawai
                        </p>

                        <h1 class="mt-5 font-bold tracking-tight text-5xl leading-tight sm:text-6xl text-ink dark:text-white">
                            Selamat Datang di
                            <span class="text-brand-green dark:text-brand-lime whitespace-nowrap">SIAP</span>
                        </h1>

                        <p class="max-w-xl mt-5 text-lg leading-relaxed text-body dark:text-gray-300">
                            Sistem Informasi Aset &amp; Pegawai <b>{{ $identitas->nama }}</b> — absensi, kepegawaian,
                            dan manajemen aset dalam satu aplikasi.
                        </p>
                        @if (filled($identitas->tagline))
                            <p class="max-w-xl mt-2 italic text-muted dark:text-gray-400">“{{ $identitas->tagline }}”</p>
                        @endif

                        {{-- CTA --}}
                        <div class="flex flex-wrap items-center gap-3 mt-8">
                            <a href="{{ Route::has('login') ? route('login') : '#' }}" wire:navigate>
                                <x-primary-button type="button" class="px-5 py-3">
                                    Masuk
                                </x-primary-button>
                            </a>

                            <x-secondary-button type="button" onclick="location.href='#tentang'">
                                Pelajari lebih lanjut
                            </x-secondary-button>
                        </div>

                        {{-- Akses cepat: Absen HP publik, sisanya lewat login --}}
                        <div class="mt-8">
                            <div class="flex items-center gap-2 mb-3">
                                <span class="inline-block w-2 h-2 rounded-full bg-brand-lime"></span>
                                <p class="text-xs font-semibold tracking-[0.12em] uppercase text-brand-green dark:text-brand-lime">
                                    Modul
                                </p>
                            </div>
                            @php
                                $modules = [
                                    ['label' => 'Absen HP', 'href' => route('absensi.hp'), 'icon' => 'M10.5 1.5H8.25A2.25 2.25 0 006 3.75v16.5a2.25 2.25 0 002.25 2.25h7.5A2.25 2.25 0 0018 20.25V3.75a2.25 2.25 0 00-2.25-2.25H13.5m-3 0V3h3V1.5m-3 0h3m-3 18.75h3'],
                                    ['label' => 'Absensi & Jadwal Dinas', 'href' => route('login'), 'icon' => 'M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z'],
                                    ['label' => 'Kepegawaian & Gaji', 'href' => route('login'), 'icon' => 'M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z'],
                                    ['label' => 'Manajemen Aset', 'href' => route('login'), 'icon' => 'M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z'],
                                ];
                            @endphp
                            <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                @foreach ($modules as $modul)
                                    <a href="{{ $modul['href'] }}"
                                        class="flex items-center gap-3 px-4 py-3 transition rounded-xl bg-surface-card ring-1 ring-hairline hover:ring-2 hover:ring-brand-green
                                            dark:bg-white/5 dark:ring-white/10 dark:hover:ring-brand-lime">
                                        <svg class="w-5 h-5 text-brand-green shrink-0 dark:text-brand-lime"
                                            fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="{{ $modul['icon'] }}" />
                                        </svg>
                                        <span class="flex-1 text-sm font-semibold text-ink dark:text-slate-100">{{ $modul['label'] }}</span>
                                        <svg class="w-4 h-4 text-muted shrink-0 dark:text-gray-400" fill="none" stroke="currentColor"
                                            viewBox="0 0 24 24" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                                        </svg>
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    </div>

                    {{-- RIGHT (Slider) --}}
                    <div x-data="{
                        i: 0,
                        slides: @js($slides),
                        interval: null,
                        next() { this.i = (this.i + 1) % this.slides.length },
                        prev() { this.i = (this.i - 1 + this.slides.length) % this.slides.length },
                        start() { this.interval = setInterval(() => this.next(), 3000) },
                        stop() { clearInterval(this.interval);
                            this.interval = null },
                    }" x-init="start()" class="relative">
                        <div
                            class="relative overflow-hidden border rounded-2xl border-hairline bg-surface-soft dark:border-white/10 dark:bg-white/5">
                            <div class="aspect-[16/10] relative">
                                <template x-for="(src, idx) in slides" :key="idx">
                                    <img x-show="i === idx" x-transition:enter="transition-opacity duration-500 ease-out"
                                        x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                                        x-transition:leave="transition-opacity duration-500 ease-in"
                                        x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
                                        :src="src" alt="Landing image"
                                        class="absolute inset-0 object-cover w-full h-full" />
                                </template>
                            </div>
                        </div>

                        <div
                            class="absolute w-24 h-24 rounded-full -top-6 -right-6 bg-brand-lime blur-2xl opacity-40 -z-10">
                        </div>
                        <div
                            class="absolute w-32 h-32 rounded-full opacity-25 -bottom-10 -left-10 bg-brand-green blur-3xl -z-10">
                        </div>
                    </div>
                </div>
            </div>

            {{-- TENTANG / PROFIL INSTANSI (Sistem › Identitas Instansi) --}}
            <div id="tentang" class="px-6 py-12 border-t scroll-mt-24 border-hairline bg-surface-soft/60 dark:bg-white/5 dark:border-white/10">
                <div class="grid items-start grid-cols-1 gap-8 mx-auto max-w-7xl lg:grid-cols-3">
                    <div class="flex flex-col items-center text-center lg:items-start lg:text-left">
                        @if ($logoIdentitas)
                            <img src="{{ $logoIdentitas }}" alt="{{ $identitas->nama }}" class="object-contain w-auto h-20 max-w-full p-3 bg-white rounded-2xl">
                        @endif
                        {{-- Ada logo → judul nama hanya untuk pembaca layar (logo sudah memuat nama) --}}
                        <h2 class="{{ $logoIdentitas ? 'sr-only' : 'text-2xl font-bold text-ink dark:text-white' }}">{{ $identitas->nama }}</h2>
                        @if (filled($identitas->tagline))
                            <p class="mt-1 italic text-muted dark:text-gray-400">{{ $identitas->tagline }}</p>
                        @endif
                    </div>

                    <div class="lg:col-span-2">
                        <p class="text-xs font-semibold tracking-[0.12em] uppercase text-brand-green dark:text-brand-lime">Tentang kami</p>
                        <p class="mt-2 leading-relaxed whitespace-pre-line text-body dark:text-gray-300">{{ $identitas->deskripsi ?: 'Profil instansi belum diisi — atur di menu Sistem › Identitas Instansi.' }}</p>

                        <dl class="grid grid-cols-1 gap-4 mt-6 text-sm sm:grid-cols-2">
                            @foreach ([
                                ['Alamat', \App\Support\Identitas::alamatLengkap()],
                                ['Jam Operasional', $identitas->jam_operasional ?? null],
                                ['Telepon', collect([$identitas->telp, $identitas->fax ? 'Fax ' . $identitas->fax : null])->filter()->implode(' · ')],
                                ['Email', $identitas->email],
                                ['Website', $identitas->website],
                                [$identitas->jabatan_pimpinan ?: 'Pimpinan', $identitas->pimpinan],
                            ] as [$label, $nilai])
                                @if (filled($nilai))
                                    <div>
                                        <dt class="font-semibold text-ink dark:text-gray-100">{{ $label }}</dt>
                                        <dd class="mt-0.5 text-body dark:text-gray-300">{{ $nilai }}</dd>
                                    </div>
                                @endif
                            @endforeach
                        </dl>
                    </div>
                </div>
            </div>

            {{-- FOOTER --}}
            <footer class="py-6 border-t border-hairline dark:border-white/10">
                <p class="text-center text-caption text-muted dark:text-gray-400">
                    © {{ date('Y') }} SIAP — {{ $identitas->nama }} · Powered by Aturapi Data Technology
                </p>
            </footer>
        </section>
    </div>
@endsection
