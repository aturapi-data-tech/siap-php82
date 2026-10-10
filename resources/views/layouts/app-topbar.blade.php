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
                    <x-dropdown-link :href="route('profile.edit')">Profil</x-dropdown-link>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <x-dropdown-link :href="route('logout')" onclick="event.preventDefault(); this.closest('form').submit();">
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

                <x-secondary-button type="button" onclick="location.href='#tentang'">
                    Bantuan
                </x-secondary-button>
            @endif
        </div>
    @endauth
@endif
