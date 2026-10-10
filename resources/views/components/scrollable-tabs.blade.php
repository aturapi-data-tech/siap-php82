{{--
    <x-scrollable-tabs>
        <ul class="flex flex-nowrap whitespace-nowrap ...">
            <li>...</li>
        </ul>
    </x-scrollable-tabs>

    Wrap tab nav `<ul>` agar:
    - Single-line scrollable (overflow-x-auto)
    - 2 tombol panah ◂ ▸ brand-colored, otomatis muncul kalau ada konten ke-hidden
    - Tombol auto-hide pas scroll sudah di edge

    Pass extra class via attribute (mis. border-b biar konsisten dgn pattern existing):
        <x-scrollable-tabs class="w-full px-2 mb-2 border-b border-gray-200 dark:border-gray-700">
--}}
@props([
    // Arrow scroll step (px)
    'step' => 200,
])

<div class="relative" x-data="{
    canLeft: false,
    canRight: false,
    update() {
        const el = this.$refs.scroll;
        if (!el) return;
        this.canLeft  = el.scrollLeft > 4;
        this.canRight = el.scrollLeft + el.clientWidth < el.scrollWidth - 4;
    },
    scrollBy(dx) {
        this.$refs.scroll.scrollBy({ left: dx, behavior: 'smooth' });
    },
}" x-init="$nextTick(() => update())" @resize.window="update()">

    {{-- ====== KIRI ====== --}}
    {{-- Gradient fade (tab text di-fade out di belakang tombol) --}}
    <div x-show="canLeft" x-cloak x-transition.opacity
        class="absolute top-0 bottom-0 left-0 z-[5] w-12 pointer-events-none bg-gradient-to-r from-white dark:from-gray-800 via-white/80 dark:via-gray-800/80 to-transparent">
    </div>

    {{-- Tombol panah kiri (circular badge brand) --}}
    <button type="button" x-show="canLeft" x-cloak x-transition.opacity
        @click="scrollBy(-{{ $step }})"
        class="absolute z-10 flex items-center justify-center w-7 h-7 transition-all -translate-y-1/2 rounded-full shadow-md left-1 top-1/2 bg-gray-500/60 hover:bg-gray-700 backdrop-blur-sm hover:scale-110 text-white ring-1 ring-white/60 dark:ring-gray-800/60"
        aria-label="Scroll kiri">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="3">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
        </svg>
    </button>

    {{-- Container scrollable + slot
         pl-10/pr-10 reactive: padding muncul kalau panah visible, biar
         tab pertama/terakhir tidak kepotong di belakang tombol.
         pb-1.5 = breathing room bawah (tab text → border / scrollbar). --}}
    <div x-ref="scroll" @scroll.passive="update"
        :class="{ 'pl-10': canLeft, 'pr-10': canRight }"
        {{ $attributes->merge(['class' => 'overflow-x-auto pb-1.5 transition-all']) }}>
        {{ $slot }}
    </div>

    {{-- ====== KANAN ====== --}}
    {{-- Gradient fade --}}
    <div x-show="canRight" x-cloak x-transition.opacity
        class="absolute top-0 bottom-0 right-0 z-[5] w-12 pointer-events-none bg-gradient-to-l from-white dark:from-gray-800 via-white/80 dark:via-gray-800/80 to-transparent">
    </div>

    {{-- Tombol panah kanan --}}
    <button type="button" x-show="canRight" x-cloak x-transition.opacity
        @click="scrollBy({{ $step }})"
        class="absolute z-10 flex items-center justify-center w-7 h-7 transition-all -translate-y-1/2 rounded-full shadow-md right-1 top-1/2 bg-gray-500/60 hover:bg-gray-700 backdrop-blur-sm hover:scale-110 text-white ring-1 ring-white/60 dark:ring-gray-800/60"
        aria-label="Scroll kanan">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="3">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
        </svg>
    </button>
</div>
