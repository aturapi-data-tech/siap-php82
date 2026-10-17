@use(Illuminate\Support\Str)

<aside x-cloak id="app-sidebar"
    class="fixed top-20 left-0 z-[60] h-[calc(100vh-5rem)] w-80 max-w-[85vw] overflow-hidden flex flex-col
           bg-canvas dark:bg-gray-900 border-r border-hairline dark:border-gray-700
           transform transition-transform duration-300 ease-out"
    :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
    {{-- Tertutup → inert: tautan menu tidak ikut urutan Tab & tidak terbaca pembaca layar --}}
    :inert="!sidebarOpen" aria-label="Menu utama">

    {{-- Super grafis: watermark logogram besar (±35% terlihat, sangat tipis) --}}
    <img src="{{ asset('images/Logogram black solid.png') }}" alt=""
        class="absolute -left-44 -bottom-44 w-[40rem] opacity-[0.04] pointer-events-none select-none dark:invert" />

    {{-- header sidebar --}}
    <div class="relative z-10 flex items-center justify-between px-4 h-14">
        <div class="flex items-center gap-2.5">
            {{-- Dot kedip (primary) --}}
            <span class="relative flex h-2.5 w-2.5">
                <span class="absolute inline-flex w-full h-full rounded-full opacity-60 bg-brand-green animate-ping"></span>
                <span class="relative inline-flex h-2.5 w-2.5 rounded-full bg-brand-green"></span>
            </span>

            {{-- User info --}}
            <div class="leading-tight">
                @auth
                    <div class="text-base font-semibold text-ink dark:text-gray-100">{{ auth()->user()->name }}</div>
                    <div class="text-sm text-muted dark:text-gray-400">{{ auth()->user()->labelPeran() ?: 'User' }}</div>
                @else
                    <div class="text-base font-semibold text-ink dark:text-gray-100">Guest</div>
                    <div class="text-sm text-muted dark:text-gray-400">Silakan login</div>
                @endauth
            </div>
        </div>
    </div>

    {{-- Garis aksen header — solid lime pendek (acuan rule manual "2. Clarifying Strategy") --}}
    <div class="relative z-10 h-[3px] w-24 mx-4 rounded-full bg-brand-lime"></div>

    {{-- Cari menu (filter Alpine client-side, pola dashboard) --}}
    <div class="relative z-10 px-3 pt-3 shrink-0">
        <x-text-input x-model="menuQuery" type="search" placeholder="Cari menu..." class="w-full text-sm" />
    </div>

    {{-- menu — sumber: $sidebarMenus (AppMenu::grouped). Auto-open group aktif. --}}
    @php
        $defaultOpenKey = null;
        if ($sidebarMenus->isNotEmpty()) {
            foreach ($sidebarMenus as $gName => $gItems) {
                if ($gItems->contains(fn($it) => request()->routeIs($it['route']))) {
                    $defaultOpenKey = Str::slug($gName);
                    break;
                }
            }
            $defaultOpenKey ??= Str::slug($sidebarMenus->keys()->first());
        }
    @endphp

    <nav class="relative z-10 flex-1 min-h-0 p-3 space-y-1 overflow-y-auto"
        @if ($defaultOpenKey) x-init="if (Object.keys(openMenus).length === 0) openMenus['{{ $defaultOpenKey }}'] = true" @endif>

        @forelse (($sidebarMenus ?? collect()) as $groupName => $items)
            @php
                $key = Str::slug($groupName);
                $isActiveGroup = $items->contains(fn($it) => request()->routeIs($it['route']));
                $groupText = Str::lower($groupName . ' ' . $items->map(fn($i) => ($i['title'] ?? '') . ' ' . ($i['desc'] ?? ''))->implode(' '));
            @endphp

            <div class="pb-1 border-b border-hairline dark:border-gray-700"
                x-show="menuQuery === '' || @js($groupText).includes(menuQuery.toLowerCase())">
                {{-- Group header --}}
                <button type="button"
                    class="flex items-center justify-between w-full gap-2 px-3 py-3 transition-colors duration-150 group rounded-lg hover:bg-brand-green/10 dark:hover:bg-brand-lime/10"
                    x-on:click="toggleMenu('{{ $key }}')" :aria-expanded="openMenus['{{ $key }}'] ? 'true' : 'false'">
                    <span class="text-base truncate min-w-0 {{ $isActiveGroup ? 'font-bold text-brand-green dark:text-brand-lime' : 'font-semibold text-body group-hover:text-ink dark:text-gray-300 dark:group-hover:text-white' }}">
                        {{ $groupName }}
                    </span>
                    <svg class="w-4 h-4 transition-colors duration-150 shrink-0 {{ $isActiveGroup ? 'text-brand-green dark:text-brand-lime' : 'text-muted group-hover:text-ink dark:text-gray-500 dark:group-hover:text-gray-200' }}"
                        :class="openMenus['{{ $key }}'] ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 10 6">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m1 1 4 4 4-4" />
                    </svg>
                </button>

                {{-- Items — aktif: pil hijau primary (teks putih). Saat cari → auto-expand --}}
                <div x-cloak x-show="openMenus['{{ $key }}'] || menuQuery.length > 0" x-collapse class="mt-0.5 space-y-0.5 pl-3">
                    @foreach ($items as $item)
                        @php
                            $isActiveItem = request()->routeIs($item['route']);
                            $itemText = Str::lower(($item['title'] ?? '') . ' ' . ($item['desc'] ?? ''));
                        @endphp
                        <a href="{{ $item['href'] }}" wire:navigate @if ($isActiveItem) aria-current="page" @endif
                            x-show="menuQuery === '' || @js($itemText).includes(menuQuery.toLowerCase())"
                            class="block px-3 py-2.5 rounded-lg transition-colors duration-150
                                   {{ $isActiveItem ? 'bg-brand-green shadow-sm' : 'hover:bg-brand-green/10 dark:hover:bg-brand-lime/10' }}">
                            {{-- Judul --}}
                            <div class="text-base leading-snug {{ $isActiveItem ? 'font-bold text-white' : 'font-medium text-body group-hover:text-brand-green dark:text-gray-300 dark:group-hover:text-brand-lime' }}">
                                {{ $item['title'] }}
                            </div>
                            {{-- Deskripsi --}}
                            @if (!empty($item['desc']))
                                <div class="text-sm leading-snug mt-0.5 line-clamp-2 {{ $isActiveItem ? 'text-white/80' : 'text-muted dark:text-gray-400' }}">
                                    {{ $item['desc'] }}
                                </div>
                            @endif
                        </a>
                    @endforeach
                </div>
            </div>
        @empty
            <div class="px-4 py-8 text-sm text-center text-muted dark:text-gray-400">
                Tidak ada menu untuk role Anda.
            </div>
        @endforelse

    </nav>
</aside>
