@props([
    // Label kolom di atas group (mis. "Aksi") — null/'' = tanpa label (default),
    // untuk toolbar yang elemen kanannya tidak berlabel.
    'label' => '',
    // Nama method Livewire untuk reset filter di komponen pemakai.
    'resetAction' => 'resetFilters',
    // true = sembunyikan teks (hanya ikon), padding lebih ringkas. Standar: icon-only.
    'iconOnly' => true,
])

{{-- Tombol standar toolbar list: Refresh + Reset (button group menyatu).
     • Refresh = $refresh Livewire — muat ulang data TANPA mengubah filter
       (ikon reload berputar saat loading).
     • Reset   = panggil $resetAction — kembalikan semua filter ke kondisi awal
       (ikon panah balik).
     Tinggi selaras x-primary-button (py-2.5 text-sm). Pola pemakaian sama
     dengan x-now-button: drop-in, atribut pass-through ke wrapper. --}}
<div {{ $attributes->class(['w-auto']) }}>
    @if (!empty($label))
        <x-input-label :value="$label" />
    @endif
    <div
        @class([
            'inline-flex items-stretch overflow-hidden bg-canvas border border-hairline divide-x divide-hairline rounded-lg shadow-sm dark:bg-gray-900 dark:border-gray-600 dark:divide-gray-600',
            'mt-1' => !empty($label),
        ])>
        <button type="button" wire:click="$refresh" title="Muat ulang data tanpa mengubah filter"
            @class([
                'inline-flex items-center gap-1.5 py-2.5 text-sm font-medium text-blue-600 transition-colors duration-150 hover:bg-blue-50 focus:outline-none focus:bg-blue-50 dark:text-blue-400 dark:hover:bg-blue-900/20',
                'px-2.5' => $iconOnly,
                'px-4' => !$iconOnly,
            ])>
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"
                wire:loading.class="animate-spin" wire:target="$refresh">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
            </svg>
            @unless ($iconOnly) Refresh @endunless
        </button>

        <button type="button" wire:click="{{ $resetAction }}" title="Kembalikan semua filter ke kondisi awal"
            @class([
                'inline-flex items-center gap-1.5 py-2.5 text-sm font-medium text-gray-600 transition-colors duration-150 hover:bg-gray-100 focus:outline-none focus:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800',
                'px-2.5' => $iconOnly,
                'px-4' => !$iconOnly,
            ])>
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M9 15L3 9m0 0l6-6M3 9h12a6 6 0 010 12h-3" />
            </svg>
            @unless ($iconOnly) Reset @endunless
        </button>
    </div>
</div>
