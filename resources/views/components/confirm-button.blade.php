@props([
    'variant' => 'danger', // danger|danger-soft|warning-soft|primary|secondary|outline
    'action', // contoh: "delete(10)" atau "delete('10')"
    'title' => 'Konfirmasi',
    'message' => 'Apakah Anda yakin?',
    'confirmText' => 'Ya',
    'cancelText' => 'Batal',
    'disabled' => false,
    'wireTarget' => null, // default: nama method dari $action — override bila perlu target lain
])

@php
    // id unik supaya aman dipakai berulang di table
    $confirmId = 'confirm_' . md5($action . '|' . ($attributes->get('wire:key') ?? '') . '|' . uniqid('', true));

    // Target wire:loading — cukup nama method (tanpa argumen) supaya aman dari
    // tanda kutip di $action. Konsekuensi: semua trigger ber-method sama ikut
    // disabled selama proses; justru mencegah aksi ganda dari baris lain.
    $loadingTarget = $wireTarget ?? \Illuminate\Support\Str::before($action, '(');

    // class trigger button — disesuaikan dengan komponen button standar
    $base = 'inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-lg text-sm font-medium whitespace-nowrap transition-colors duration-150';

    $triggerButtonClass = match ($variant) {
        'primary'
            => $base . ' text-white bg-brand-green hover:bg-brand-green/90 focus:outline-none focus:ring-4 focus:ring-brand-lime/40 dark:bg-brand-lime dark:text-gray-900 dark:hover:bg-brand-lime/90',
        'secondary'
            => $base . ' text-gray-700 bg-gray-100 border border-gray-300 hover:bg-gray-200 hover:text-gray-900 focus:outline-none focus:ring-4 focus:ring-gray-200 dark:bg-gray-800 dark:text-gray-200 dark:border-gray-600 dark:hover:bg-gray-700',
        'outline'
            => $base . ' text-brand-green bg-brand-green/10 border border-brand-green/30 hover:bg-brand-green hover:text-white hover:border-brand-green focus:outline-none focus:ring-4 focus:ring-brand-green/20 dark:text-brand-lime dark:bg-brand-lime/10 dark:border-brand-lime/30 dark:hover:bg-brand-lime dark:hover:text-gray-900',
        // Merah bertint (bukan solid) — tampilan tombol hapus ikon di dalam tabel/form,
        // seperti di e-resep. Dibuat jadi VARIAN supaya pemakai tak perlu override
        // `!important` (dilarang Aturan Umum standar-ui-komponen.md).
        // Kuning lembut — aksi KOREKSI (Buka Kunci, batal proses): berbeda dari merah hapus supaya
        // dua tombol berisiko di satu sel tidak tampak sama.
        'warning-soft'
            => $base . ' text-amber-700 bg-amber-50 border border-amber-200 hover:bg-amber-100 hover:text-amber-700 hover:border-amber-300 focus:outline-none focus:ring-4 focus:ring-amber-200 dark:text-amber-400 dark:bg-amber-900/20 dark:border-amber-800/30 dark:hover:bg-amber-900/30 dark:hover:text-amber-300 dark:focus:ring-amber-900',
        'danger-soft'
            => $base . ' text-red-600 bg-red-50 border border-red-200 hover:bg-red-100 hover:text-red-700 hover:border-red-300 focus:outline-none focus:ring-4 focus:ring-red-200 dark:text-red-400 dark:bg-red-900/20 dark:border-red-800/30 dark:hover:bg-red-900/30 dark:hover:text-red-300 dark:focus:ring-red-900',
        default
            => $base . ' text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-4 focus:ring-red-300 dark:bg-red-600 dark:hover:bg-red-700 dark:focus:ring-red-900',
    };
@endphp

<div x-data="{
    show: false,
    open() {
        this.show = true;
        document.body.classList.add('overflow-hidden');
        this.$nextTick(() => {
            // fokus ke tombol confirm biar enak keyboard
            this.$refs.confirmButton?.focus();
        });
    },
    close() {
        this.show = false;
        document.body.classList.remove('overflow-hidden');
    },
    confirm() {
        this.close();
        $wire.{{ $action }};
    }
}" x-on:keydown.escape.window="if (show) close()" class="inline-block">
    {{-- Trigger — disabled + spinner selama aksi $wire berjalan, supaya proses
         lama tidak terkesan "tidak jalan" setelah user menekan tombol konfirmasi --}}
    <button type="button" @disabled($disabled) x-on:click="open()" wire:loading.attr="disabled"
        wire:target="{{ $loadingTarget }}"
        {{ $attributes->merge(['class' => $triggerButtonClass . ' disabled:opacity-60 disabled:cursor-not-allowed']) }}>
        <span wire:loading.remove wire:target="{{ $loadingTarget }}" class="inline-flex items-center gap-2">
            {{ $slot }}
        </span>
        <span wire:loading wire:target="{{ $loadingTarget }}" class="inline-flex items-center gap-2">
            <x-loading class="w-4 h-4" />
            Memproses...
        </span>
    </button>

    {{-- Modal Confirm (transisi DISAMAIN dengan <x-modal>) --}}
    <div x-cloak x-show="show" class="fixed inset-0 z-[90]" aria-labelledby="{{ $confirmId }}_title" role="dialog"
        aria-modal="true">
        {{-- Overlay (SAMA seperti modal) --}}
        <div class="fixed inset-0 bg-gray-900/50 dark:bg-gray-900/70" x-on:click="close()"
            x-transition:enter="ease-out duration-150" x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100" x-transition:leave="ease-in duration-120"
            x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"></div>

        {{-- Panel --}}
        <div class="fixed inset-0 flex items-center justify-center p-4">
            <div class="w-full max-w-md overflow-hidden bg-white border border-gray-200 shadow-2xl rounded-2xl dark:bg-gray-800 dark:border-gray-700"
                x-on:click.stop x-transition:enter="ease-out duration-150"
                x-transition:enter-start="opacity-0 translate-y-2 sm:scale-95"
                x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                x-transition:leave="ease-in duration-120"
                x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                x-transition:leave-end="opacity-0 translate-y-2 sm:scale-95">
                {{-- Header --}}
                <div class="flex items-start justify-between px-5 py-4 border-b border-gray-200 dark:border-gray-700">
                    <div>
                        <h3 id="{{ $confirmId }}_title"
                            class="text-base font-semibold text-gray-900 dark:text-gray-100">
                            {{ $title }}
                        </h3>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            {{ $message }}
                        </p>
                    </div>

                    <button type="button"
                        class="inline-flex items-center justify-center text-gray-500 rounded-lg w-9 h-9 hover:text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700"
                        x-on:click="close()" aria-label="Tutup">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd"
                                d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z"
                                clip-rule="evenodd" />
                        </svg>
                    </button>
                </div>

                {{-- Footer --}}
                <div class="flex items-center justify-end gap-2 px-5 py-4 bg-gray-50/70 dark:bg-gray-900/20">
                    <x-secondary-button type="button" x-on:click="close()">
                        {{ $cancelText }}
                    </x-secondary-button>

                    <x-danger-button type="button" x-ref="confirmButton" x-on:click="confirm()">
                        {{ $confirmText }}
                    </x-danger-button>
                </div>
            </div>
        </div>
    </div>
</div>
