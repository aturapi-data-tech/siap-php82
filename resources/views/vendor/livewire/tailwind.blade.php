{{-- Pagination Livewire (override vendor livewire::tailwind) — gaya Flowbite v4 dengan token brand SIAP.
     Halaman aktif = hijau Madinah; mode gelap ikut token --fb-*. Perilaku (wire:click, scrollTo, dusk) sama dengan bawaan. --}}
@php
if (! isset($scrollTo)) {
    $scrollTo = 'body';
}

$scrollIntoViewJsSnippet = ($scrollTo !== false)
    ? <<<JS
       (\$el.closest('{$scrollTo}') || document.querySelector('{$scrollTo}')).scrollIntoView()
    JS
    : '';

// Tiap tombol berdiri sendiri & membulat (rounded-base), dipisah gap — bukan grup menyambung.
$tombol = 'inline-flex items-center justify-center h-9 text-sm font-medium border border-default-medium rounded-base transition-colors duration-150 focus:outline-none focus:ring-4 focus:ring-brand-medium';
$aktifkan = 'text-body bg-neutral-secondary-medium hover:bg-neutral-tertiary-medium hover:text-heading dark:text-gray-300 dark:hover:text-white';
$mati = 'text-fg-disabled bg-neutral-secondary-medium cursor-not-allowed';
$panahKiri = 'M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z';
$panahKanan = 'M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z';
$halamanAkhir = $paginator->getPageName() == 'page' ? '' : '.' . $paginator->getPageName();
@endphp

<div>
    @if ($paginator->hasPages())
        <nav role="navigation" aria-label="Navigasi halaman" class="flex items-center justify-between gap-3">
            {{-- HP: Sebelumnya / Berikutnya --}}
            <div class="flex justify-between flex-1 gap-3 sm:hidden">
                @if ($paginator->onFirstPage())
                    <span class="{{ $tombol }} {{ $mati }} px-4" aria-disabled="true">Sebelumnya</span>
                @else
                    <button type="button" wire:click="previousPage('{{ $paginator->getPageName() }}')" x-on:click="{{ $scrollIntoViewJsSnippet }}" wire:loading.attr="disabled"
                        dusk="previousPage{{ $halamanAkhir }}.before" class="{{ $tombol }} {{ $aktifkan }} px-4">Sebelumnya</button>
                @endif

                @if ($paginator->hasMorePages())
                    <button type="button" wire:click="nextPage('{{ $paginator->getPageName() }}')" x-on:click="{{ $scrollIntoViewJsSnippet }}" wire:loading.attr="disabled"
                        dusk="nextPage{{ $halamanAkhir }}.before" class="{{ $tombol }} {{ $aktifkan }} px-4">Berikutnya</button>
                @else
                    <span class="{{ $tombol }} {{ $mati }} px-4" aria-disabled="true">Berikutnya</span>
                @endif
            </div>

            {{-- Layar lebar: keterangan + nomor halaman --}}
            <div class="hidden sm:flex-1 sm:flex sm:items-center sm:justify-between sm:gap-3">
                <p class="text-sm text-body dark:text-gray-300 tabular-nums">
                    Menampilkan <span class="font-semibold text-heading">{{ $paginator->firstItem() }}</span>–<span class="font-semibold text-heading">{{ $paginator->lastItem() }}</span>
                    dari <span class="font-semibold text-heading">{{ $paginator->total() }}</span> data
                </p>

                <div class="inline-flex items-center gap-1 rtl:flex-row-reverse tabular-nums">
                    {{-- Sebelumnya --}}
                    @if ($paginator->onFirstPage())
                        <span class="{{ $tombol }} {{ $mati }} px-3" aria-disabled="true" aria-label="Halaman sebelumnya">
                            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="{{ $panahKiri }}" clip-rule="evenodd" /></svg>
                        </span>
                    @else
                        <button type="button" wire:click="previousPage('{{ $paginator->getPageName() }}')" x-on:click="{{ $scrollIntoViewJsSnippet }}"
                            dusk="previousPage{{ $halamanAkhir }}.after" class="{{ $tombol }} {{ $aktifkan }} px-3" aria-label="Halaman sebelumnya">
                            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="{{ $panahKiri }}" clip-rule="evenodd" /></svg>
                        </button>
                    @endif

                    {{-- Nomor halaman --}}
                    @foreach ($elements as $element)
                        @if (is_string($element))
                            <span class="{{ $tombol }} {{ $mati }} w-9" aria-disabled="true">{{ $element }}</span>
                        @endif

                        @if (is_array($element))
                            @foreach ($element as $page => $url)
                                <span wire:key="paginator-{{ $paginator->getPageName() }}-page{{ $page }}" class="inline-flex">
                                    @if ($page == $paginator->currentPage())
                                        <span aria-current="page" class="{{ $tombol }} w-9 font-semibold text-white shadow-xs bg-brand border-brand dark:bg-brand-lime dark:text-ink dark:border-brand-lime">{{ $page }}</span>
                                    @else
                                        <button type="button" wire:click="gotoPage({{ $page }}, '{{ $paginator->getPageName() }}')" x-on:click="{{ $scrollIntoViewJsSnippet }}"
                                            class="{{ $tombol }} {{ $aktifkan }} w-9" aria-label="Ke halaman {{ $page }}">{{ $page }}</button>
                                    @endif
                                </span>
                            @endforeach
                        @endif
                    @endforeach

                    {{-- Berikutnya --}}
                    @if ($paginator->hasMorePages())
                        <button type="button" wire:click="nextPage('{{ $paginator->getPageName() }}')" x-on:click="{{ $scrollIntoViewJsSnippet }}"
                            dusk="nextPage{{ $halamanAkhir }}.after" class="{{ $tombol }} {{ $aktifkan }} px-3" aria-label="Halaman berikutnya">
                            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="{{ $panahKanan }}" clip-rule="evenodd" /></svg>
                        </button>
                    @else
                        <span class="{{ $tombol }} {{ $mati }} px-3" aria-disabled="true" aria-label="Halaman berikutnya">
                            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="{{ $panahKanan }}" clip-rule="evenodd" /></svg>
                        </span>
                    @endif
                </div>
            </div>
        </nav>
    @endif
</div>
