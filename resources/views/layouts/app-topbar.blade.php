@if (Route::has('login'))
    @auth
        <div class="flex items-center gap-2 shrink-0">
            {{-- TOP NAV (desktop only) --}}
            <nav class="items-center hidden gap-1 mr-2 lg:flex">
                {{-- Dashboard (active) --}}
                <a href="{{ route('dashboard') }}" wire:navigate
                    class="px-3 py-2 text-sm font-medium transition-colors duration-200 rounded-md text-brand-green bg-brand-green/10 dark:text-brand-lime dark:bg-brand-lime/15">
                    Dashboard
                </a>
            </nav>

            <x-theme-toggle />

            <x-dropdown align="right" width="48">
                <x-slot name="trigger">
                    <button class="flex items-center">
                        <div class="overflow-hidden rounded-full w-9 h-9 bg-brand-green/10 dark:bg-brand-lime/15">
                            <img class="object-cover w-full h-full"
                                src="https://ui-avatars.com/api/?name={{ urlencode(auth()->user()->name ?? 'User') }}"
                                alt="avatar">
                        </div>
                    </button>
                </x-slot>

                <x-slot name="content">
                    <x-dropdown-link :href="route('profile.edit')">
                        <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" />
                        </svg>
                        Profil
                    </x-dropdown-link>
                    {{-- Keluar dipisah garis & berwarna bahaya (aksi keluar terpisah dari navigasi biasa) --}}
                    <form method="POST" action="{{ route('logout') }}" class="pt-1 mt-1 border-t border-default-medium">
                        @csrf
                        <x-dropdown-link :href="route('logout')" onclick="event.preventDefault(); this.closest('form').submit();" bahaya>
                            <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l-3 3m0 0l3 3m-3-3h12.75" />
                            </svg>
                            Keluar
                        </x-dropdown-link>
                    </form>
                </x-slot>
            </x-dropdown>
        </div>
    @else
        <div class="flex items-center gap-2 shrink-0">
            <x-theme-toggle />

            {{-- Masuk — hanya tampil di halaman root (welcome).
                 Halaman publik lain (display TV, dll) tidak butuh tombol Masuk. --}}
            @if (request()->path() === '/')
                <x-primary-button type="button" onclick="location.href='{{ route('login') }}'">
                    Masuk
                </x-primary-button>

                <x-secondary-button type="button" x-on:click="$dispatch('open-modal', { name: 'tentang' })">
                    Bantuan
                </x-secondary-button>
            @endif
        </div>
    @endauth
@endif
