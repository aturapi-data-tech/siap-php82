<?php

use App\Services\AppMenu;
use Livewire\Component;
use Livewire\Attributes\Computed;

new class extends Component {
    public string $search = '';

    // ✅ Semua role user (lowercase) dipakai untuk filtering menu
    #[Computed]
    public function userRoles(): array
    {
        return auth()->user()->getRoleNames()->map(fn($r) => trim(strtolower($r)))->values()->toArray();
    }

    #[Computed]
    public function masterMenus(): array
    {
        // Definisi menu dipusatkan di App\Services\AppMenu (dipakai dashboard + sidebar).
        return AppMenu::all();
    }

    #[Computed]
    public function visibleMenus(): array
    {
        return AppMenu::forRoles($this->userRoles);
    }

    #[Computed]
    public function groupedMenus()
    {
        $grouped = AppMenu::grouped($this->userRoles);

        $q = trim(mb_strtolower($this->search));
        if ($q === '') {
            return $grouped;
        }

        // Cari di title / desc / badge / nama group. Group dengan 0 match auto-hidden.
        return $grouped
            ->map(fn($items, $groupName) => $items->filter(function ($item) use ($q, $groupName) {
                $haystack = mb_strtolower(($item['title'] ?? '') . ' ' . ($item['desc'] ?? '') . ' ' . ($item['badge'] ?? '') . ' ' . $groupName);
                return str_contains($haystack, $q);
            }))
            ->filter(fn($items) => $items->isNotEmpty());
    }
};
?>

<div>

    {{-- Judul di topbar (sebelah logo) — pola master --}}
    <x-page-title
        title="Dashboard"
        subtitle="Pusat menu aplikasi — Role Aktif : {{ auth()->user()->getRoleNames()->implode(', ') }}" />

    <div class="w-full h-[calc(100vh-5rem)] flex flex-col bg-surface-soft dark:bg-gray-900">
        <div class="flex flex-col flex-1 min-h-0 px-6 pt-2 pb-6">

            {{-- TOOLBAR (persis master poli: sticky py-3) --}}
            <div
                class="sticky z-30 px-4 py-3 bg-surface-soft border-b border-hairline top-20 dark:bg-gray-900 dark:border-gray-700">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                    {{-- SEARCH --}}
                    <div class="w-full lg:max-w-md">
                        <x-input-label for="search" value="Cari Menu" class="sr-only" />
                        <x-text-input id="search" wire:model.live.debounce.250ms="search" placeholder="Cari menu..."
                            class="block w-full" />
                    </div>
                    <div class="hidden lg:block"></div>
                </div>
            </div>

            {{-- GRID MENU — Accordion (area scroll, jarak mt-4 spt kartu master) --}}
            <div class="flex-1 min-h-0 mt-4 overflow-y-auto" x-data="{ activeGroup: null }">

                @forelse ($this->groupedMenus as $groupName => $menus)
                    <div x-data="{ group: '{{ $groupName }}' }">

                        {{-- GROUP HEADER (eyebrow) --}}
                        <button type="button" @click="activeGroup = (activeGroup === group) ? null : group"
                            class="flex items-center w-full gap-3 mt-6 mb-3 group/header">
                            <h2 class="text-xs font-semibold uppercase tracking-[0.12em] whitespace-nowrap transition-colors
                                       text-muted group-hover/header:text-ink dark:text-gray-500 dark:group-hover/header:text-gray-300"
                                :class="activeGroup === group ? 'text-brand-green dark:text-brand-lime' : ''">
                                {{ $groupName }}
                            </h2>
                            {{-- Ornamen garis lime + hairline (Graphic Standard Manual) --}}
                            <div class="flex items-center flex-1 gap-2">
                                <span class="w-7 h-[3px] rounded-full bg-brand-lime shrink-0 transition-all duration-500 ease-out group-hover/header:w-24"></span>
                                <span class="flex-1 h-px bg-hairline dark:bg-gray-700"></span>
                            </div>
                            <svg class="w-4 h-4 transition-transform duration-200 shrink-0 text-muted"
                                :class="activeGroup === group ? 'rotate-0 text-brand-green dark:text-brand-lime' : '-rotate-90'"
                                fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                            </svg>
                        </button>

                        {{-- GRID KARTU --}}
                        <div x-show="activeGroup === group" x-transition:enter="transition ease-out duration-200"
                            x-transition:enter-start="opacity-0 -translate-y-2"
                            x-transition:enter-end="opacity-100 translate-y-0"
                            x-transition:leave="transition ease-in duration-150"
                            x-transition:leave-start="opacity-100 translate-y-0"
                            x-transition:leave-end="opacity-0 -translate-y-2"
                            class="grid grid-cols-1 gap-4 mb-2 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5">

                            @foreach ($menus as $m)
                                <a href="{{ $m['href'] }}" wire:navigate
                                    class="relative block p-4 pt-5 overflow-hidden transition-all duration-200 border group bg-surface-elevated border-hairline rounded-xl shadow-sm hover:border-brand-green/40 hover:shadow-md hover:-translate-y-0.5 dark:bg-gray-800/40 dark:border-gray-700 dark:hover:border-brand-lime/40">
                                    {{-- Ornamen aksen lime (Graphic Standard Manual) — memanjang saat hover --}}
                                    <span class="absolute top-0 left-0 h-1 transition-all duration-500 ease-out rounded-b w-9 bg-brand-lime group-hover:w-full"></span>
                                    <div class="flex items-start justify-between gap-2">
                                        <h3 class="text-base font-semibold leading-snug transition-colors text-ink group-hover:text-brand-green dark:text-gray-100 dark:group-hover:text-brand-lime">
                                            {{ $m['title'] }}
                                        </h3>
                                        @if (!empty($m['badge']))
                                            <span class="shrink-0 inline-flex items-center px-2 py-0.5 text-xs font-semibold rounded-full bg-brand-green/10 text-brand-green dark:bg-brand-lime/15 dark:text-brand-lime">
                                                {{ $m['badge'] }}
                                            </span>
                                        @endif
                                    </div>
                                    <p class="mt-1 text-sm leading-snug line-clamp-2 text-muted dark:text-gray-400">
                                        {{ $m['desc'] }}
                                    </p>
                                </a>
                            @endforeach

                        </div>
                    </div>

                @empty
                    <div class="py-10 text-sm text-center text-muted dark:text-gray-400">
                        Menu tidak ditemukan / tidak ada akses.
                    </div>
                @endforelse

            </div>

        </div>
    </div>
</div>
