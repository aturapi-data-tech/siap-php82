<?php

use Livewire\Component;

new class extends Component {
    // state optional
};
?>

@extends('layouts.app-welcome')

@section('content')
    {{-- Satu layar penuh tanpa scroll: banner mengisi sisa tinggi (flex-1), kartu & footer setinggi isinya.
         Layar sangat pendek → baru boleh scroll (overflow-y-auto) daripada isi terpotong. --}}
    <div class="relative w-full h-[calc(100dvh-5rem)] overflow-y-auto bg-surface-soft dark:bg-gray-900">
        @php
            $identitas = \App\Support\Identitas::get();
            $logoIdentitas = \App\Support\Identitas::logoUrl();
            $slides = collect(range(1, 9))->map(fn($n) => asset("images/landing/slide-{$n}.png"))->all();
            // Absen HP terbuka tanpa login; modul lain lewat halaman Masuk.
            $modules = [
                ['label' => 'Absen HP', 'desc' => 'Absen masuk & pulang dari HP — GPS dan selfie.', 'href' => route('absensi.hp'), 'publik' => true, 'icon' => 'M10.5 1.5H8.25A2.25 2.25 0 006 3.75v16.5a2.25 2.25 0 002.25 2.25h7.5A2.25 2.25 0 0018 20.25V3.75a2.25 2.25 0 00-2.25-2.25H13.5m-3 0V3h3V1.5m-3 0h3m-3 18.75h3'],
                ['label' => 'Absensi & Jadwal Dinas', 'desc' => 'Jadwal bulanan, kiosk, rekap harian & bulanan.', 'href' => route('login'), 'publik' => false, 'icon' => 'M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z'],
                ['label' => 'Kepegawaian & Gaji', 'desc' => 'Data karyawan, STR/SIP, slip gaji bulanan.', 'href' => route('login'), 'publik' => false, 'icon' => 'M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z'],
                ['label' => 'Manajemen Aset', 'desc' => 'Inventaris, kalibrasi, pemeliharaan, perbaikan alat.', 'href' => route('login'), 'publik' => false, 'icon' => 'M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z'],
            ];
        @endphp

        {{-- WRAPPER flex supaya footer nempel bawah --}}
        <section class="flex flex-col h-full">
            <div class="flex flex-col flex-1 min-h-0 px-4 pt-4 sm:px-6 sm:pt-6">

                {{-- HERO: banner foto RS penuh lebar + teks di atas lapisan putih terang (mode gelap: hijau hutan).
                     Slider berhenti saat disorot / difokus, bisa dijeda, dan diam bila prefers-reduced-motion. --}}
                <div x-data="{
                        i: 0,
                        jumlah: {{ count($slides) }},
                        t: null,
                        jalan: true,
                        start() {
                            if (this.t || !this.jalan || matchMedia('(prefers-reduced-motion: reduce)').matches) return;
                            this.t = setInterval(() => this.i = (this.i + 1) % this.jumlah, 5000);
                        },
                        stop() { clearInterval(this.t); this.t = null },
                        alihJalan() { this.jalan = !this.jalan; this.jalan ? this.start() : this.stop() },
                        destroy() { this.stop() },
                    }" x-init="start()" x-on:mouseenter="stop()" x-on:mouseleave="start()"
                    x-on:focusin="stop()" x-on:focusout="start()"
                    class="relative flex flex-col justify-center flex-1 w-full mx-auto overflow-hidden min-h-[17rem] max-w-7xl rounded-3xl bg-surface-strong dark:bg-surface-dark">
                    @foreach ($slides as $k => $src)
                        <img src="{{ $src }}" alt="" loading="{{ $k === 0 ? 'eager' : 'lazy' }}" decoding="async"
                            x-show="i === {{ $k }}" @if ($k > 0) x-cloak @endif
                            x-transition:enter="transition-opacity duration-700 ease-out motion-reduce:transition-none"
                            x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                            class="absolute inset-0 object-cover w-full h-full">
                    @endforeach
                    {{-- Lapisan terang: penuh di HP, memudar ke kanan di layar lebar supaya foto tetap terlihat.
                         Mode gelap tetap lapisan hijau hutan. --}}
                    <div class="absolute inset-0 bg-white/85 lg:bg-transparent lg:bg-gradient-to-r lg:from-white lg:via-white/85 lg:to-white/0
                                dark:bg-surface-dark/80 dark:lg:bg-transparent dark:lg:from-surface-dark/95 dark:lg:via-surface-dark/75 dark:lg:to-surface-dark/10"></div>

                    <div class="relative max-w-2xl px-6 pt-10 pb-16 sm:px-10 lg:pb-20 lg:px-14">
                        <p class="inline-flex items-center self-start gap-2 px-3 py-1.5 text-caption font-semibold uppercase tracking-wide rounded-full
                                  text-brand-green bg-surface-strong ring-1 ring-brand-green/20
                                  dark:text-brand-lime dark:bg-white/10 dark:ring-white/20">
                            <span class="w-2 h-2 rounded-full bg-brand-lime"></span>
                            Sistem Informasi Aset &amp; Pegawai
                        </p>

                        <h1 class="mt-4 text-3xl font-bold leading-tight tracking-tight text-ink sm:text-5xl lg:text-6xl dark:text-white">
                            Selamat Datang di <span class="text-brand-green whitespace-nowrap dark:text-brand-lime">SIAP</span>
                        </h1>

                        <p class="max-w-xl mt-3 text-base leading-relaxed sm:text-lg text-body dark:text-white/85">
                            Absensi, kepegawaian, dan manajemen aset <b class="text-ink dark:text-white">{{ $identitas->nama }}</b> dalam satu aplikasi.
                        </p>
                        @if (filled($identitas->tagline))
                            <p class="max-w-xl mt-2 italic text-muted dark:text-white/70">“{{ $identitas->tagline }}”</p>
                        @endif

                        {{-- CTA: tautan bergaya tombol (bukan <button> di dalam <a>) --}}
                        <div class="flex flex-wrap items-center gap-3 mt-6 sm:mt-8">
                            <a href="{{ route('login') }}" wire:navigate
                                class="inline-flex items-center justify-center px-6 py-3 text-base font-semibold text-white transition shadow-sm rounded-xl bg-brand-green hover:bg-brand-green-active
                                       focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-green focus-visible:ring-offset-2
                                       dark:bg-brand-lime dark:text-ink dark:hover:bg-brand-lime/90 dark:focus-visible:ring-brand-lime dark:focus-visible:ring-offset-surface-dark">
                                Masuk
                            </a>
                            <a href="{{ route('absensi.hp') }}"
                                class="inline-flex items-center justify-center gap-2 px-6 py-3 text-base font-semibold transition border rounded-xl text-brand-green bg-white/70 border-brand-green/40 hover:bg-surface-strong
                                       focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-green
                                       dark:text-white dark:bg-transparent dark:border-white/40 dark:hover:bg-white/10 dark:focus-visible:ring-white">
                                Absen HP
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
                                </svg>
                            </a>
                        </div>
                    </div>

                    {{-- Kontrol slider: titik per foto + jeda --}}
                    <div class="absolute flex items-center gap-1 py-1 pl-2 pr-1 rounded-full right-4 bottom-14 bg-surface-dark/45 backdrop-blur-sm sm:right-6 lg:top-6 lg:bottom-auto">
                        @foreach ($slides as $k => $src)
                            <button type="button" x-on:click="i = {{ $k }}" aria-label="Foto {{ $k + 1 }}"
                                :aria-current="i === {{ $k }} ? 'true' : 'false'"
                                class="grid w-6 h-6 rounded-full place-items-center focus:outline-none focus-visible:ring-2 focus-visible:ring-white">
                                <span class="block h-1.5 rounded-full transition-all motion-reduce:transition-none"
                                    :class="i === {{ $k }} ? 'w-5 bg-brand-lime' : 'w-1.5 bg-white/60'"></span>
                            </button>
                        @endforeach
                        <button type="button" x-on:click="alihJalan()" :aria-label="jalan ? 'Jeda slide foto' : 'Putar slide foto'"
                            class="grid ml-1 rounded-full w-8 h-8 place-items-center text-white bg-white/15 hover:bg-white/25 focus:outline-none focus-visible:ring-2 focus-visible:ring-white">
                            <svg x-show="jalan" class="w-3.5 h-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M6 4h3v12H6zM11 4h3v12h-3z" /></svg>
                            <svg x-show="!jalan" x-cloak class="w-3.5 h-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M6 4l10 6-10 6z" /></svg>
                        </button>
                    </div>
                </div>

                {{-- MODUL: kartu putih yang menumpuk ke tepi bawah banner --}}
                <nav aria-label="Modul SIAP" class="relative grid w-full grid-cols-2 gap-3 mx-auto -mt-10 shrink-0 max-w-6xl lg:grid-cols-4 sm:px-6">
                    @foreach ($modules as $modul)
                        <a href="{{ $modul['href'] }}" @unless ($modul['publik']) wire:navigate @endunless
                            class="flex flex-col gap-2 p-4 transition border shadow-sm sm:gap-3 sm:p-5 group rounded-2xl bg-canvas border-hairline hover:border-brand-green hover:shadow-md
                                   focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-green focus-visible:ring-offset-2
                                   dark:bg-gray-800 dark:border-gray-700 dark:hover:border-brand-lime dark:focus-visible:ring-brand-lime dark:focus-visible:ring-offset-gray-900">
                            <span class="grid w-10 h-10 rounded-xl place-items-center {{ $modul['publik'] ? 'bg-brand-lime text-ink' : 'bg-surface-strong text-brand-green dark:bg-brand-lime/10 dark:text-brand-lime' }}">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="{{ $modul['icon'] }}" />
                                </svg>
                            </span>
                            <span>
                                <span class="block text-sm font-semibold leading-snug sm:text-base text-ink dark:text-gray-100">{{ $modul['label'] }}</span>
                                <span class="hidden mt-1 text-sm leading-snug sm:block text-muted">{{ $modul['desc'] }}</span>
                            </span>
                            <span class="items-center hidden gap-1 mt-auto text-sm font-semibold sm:inline-flex text-brand-green dark:text-brand-lime">
                                {{ $modul['publik'] ? 'Buka tanpa login' : 'Masuk untuk membuka' }}
                                <svg class="w-4 h-4 transition-transform group-hover:translate-x-0.5 motion-reduce:transition-none" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                                </svg>
                            </span>
                        </a>
                    @endforeach
                </nav>
            </div>

            {{-- FOOTER ringkas — profil instansi dibuka lewat modal (tombol Bantuan / Tentang) --}}
            <footer class="px-4 py-3 shrink-0">
                <p class="text-center text-caption text-muted">
                    © {{ date('Y') }} SIAP — {{ $identitas->nama }} · Powered by Aturapi Data Technology ·
                    <button type="button" x-data x-on:click="$dispatch('open-modal', { name: 'tentang' })"
                        class="font-semibold underline rounded text-brand-green dark:text-brand-lime focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-green">Tentang {{ \App\Support\Identitas::namaPendek() }}</button>
                </p>
            </footer>
        </section>

    {{-- TENTANG / PROFIL INSTANSI (Sistem › Identitas Instansi) — di dalam akar (Livewire: satu elemen akar) --}}
    <x-modal name="tentang" size="3xl" focusable>
        <div class="flex justify-end -mt-1 -mr-1">
            <button type="button" x-on:click="$dispatch('close-modal', { name: 'tentang' })" aria-label="Tutup"
                class="grid w-9 h-9 rounded-lg place-items-center text-muted hover:bg-surface-card focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-green dark:hover:bg-gray-800">
                <svg class="w-5 h-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" clip-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" /></svg>
            </button>
        </div>
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
    </x-modal>
    </div>
@endsection
