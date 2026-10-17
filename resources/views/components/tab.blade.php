{{--
    <x-tab> — satu item di dalam <x-tabs> (Standar UI siRUS v2).

    Server mode (aktif dari Livewire/PHP):
        <x-tab :active="$activeTab === 'rj'" color="emerald" wire:click="setTab('rj')">Rawat Jalan</x-tab>

    Alpine mode (aktif dihitung client-side, instan tanpa round-trip):
        <x-tab active-expr="tab === 'RiVisit'" x-on:click="tab = 'RiVisit'">Visit</x-tab>

    Props:
    - variant   : diwarisi dari <x-tabs> via @aware (pill|underline|card|chip). Override per item boleh.
    - active    : (bool) state aktif — dipakai di server mode.
    - active-expr: (string JS) ekspresi Alpine; kalau diisi → pakai mode Alpine (x-bind:class reaktif).
    - color     : warna aktif utk variant underline & pill — brand|emerald|rose|blue|purple|violet
                  (default: emerald utk underline, brand utk pill).
    - Atribut lain (wire:click, x-on:click, title, dll) diteruskan ke <button>.
--}}
@aware(['variant' => 'underline'])
@props([
    'active' => false,
    'activeExpr' => null,
    'color' => null,
])

@php
    // variant: prop eksplisit di <x-tab> menang; kalau tidak ada, warisi dari <x-tabs> via @aware;
    // fallback 'underline'. ($variant ?? ...) aman walau @aware tak set (mis. <x-tab> tanpa induk <x-tabs>).
    $variant = $attributes->get('variant', $variant ?? 'underline');

    // Warna bawaan bila `color` tak diisi: underline → emerald, lainnya → brand.
    $color ??= $variant === 'underline' ? 'emerald' : 'brand';

    // Tampilan = sirus-php82. Tambahan SIAP (tidak mengubah tampilan): cincin fokus keyboard + role/aria-selected.
    $base = 'px-4 py-2 text-title-sm font-medium whitespace-nowrap transition-colors focus:outline-none cursor-pointer'
        . ' focus-visible:ring-2 focus-visible:ring-brand-green focus-visible:ring-offset-2 dark:focus-visible:ring-brand-lime dark:focus-visible:ring-offset-gray-900';

    // warna aktif (hanya underline & pill yg colorable; card/chip selalu brand)
    $palette = [
        // underline: tab aktif = latar lembut senada (surface-strong / tint) + teks berwarna,
        // supaya menyatu dengan tab nonaktif bg-surface-soft dan tidak mencolok.
        'underline' => [
            'brand'   => 'bg-surface-strong border-hairline text-brand dark:bg-surface-dark-elevated dark:border-gray-600 dark:text-brand-lime',
            'emerald' => 'bg-surface-strong border-hairline text-brand dark:bg-surface-dark-elevated dark:border-gray-600 dark:text-brand-lime',
            'rose'    => 'bg-rose-50 border-rose-200 text-rose-700 dark:bg-rose-900/30 dark:border-rose-800 dark:text-rose-300',
            'blue'    => 'bg-blue-50 border-blue-200 text-blue-700 dark:bg-blue-900/30 dark:border-blue-800 dark:text-blue-300',
            'purple'  => 'bg-purple-50 border-purple-200 text-purple-700 dark:bg-purple-900/30 dark:border-purple-800 dark:text-purple-300',
            'violet'  => 'bg-violet-50 border-violet-200 text-violet-700 dark:bg-violet-900/30 dark:border-violet-800 dark:text-violet-300',
        ],
        'pill' => [
            'brand'   => 'bg-brand text-white shadow-[0_1px_3px_rgba(15,86,52,0.35)]',
            'emerald' => 'bg-emerald-600 text-white',
            'rose'    => 'bg-rose-600 text-white',
            'blue'    => 'bg-blue-600 text-white',
            'purple'  => 'bg-purple-600 text-white',
            'violet'  => 'bg-violet-600 text-white',
        ],
    ];

    $map = [
        'pill' => [
            'shape'    => 'rounded-[7px]',
            'active'   => ($palette['pill'][$color] ?? $palette['pill']['brand']) . ' font-semibold',
            'inactive' => 'text-muted hover:text-ink hover:bg-surface-card dark:text-gray-400 dark:hover:text-gray-100 dark:hover:bg-gray-700',
        ],
        'underline' => [
            'shape'    => '-mb-px rounded-t-md border',
            'active'   => ($palette['underline'][$color] ?? $palette['underline']['brand']) . ' font-semibold',
            // Tab nonaktif ikut berbingkai tipis supaya ada garis pemisah antar tab.
            'inactive' => 'bg-canvas border-hairline text-muted hover:text-body hover:bg-surface-soft dark:bg-gray-800 dark:border-gray-700 dark:text-gray-400 dark:hover:text-gray-200 dark:hover:bg-gray-700',
        ],
        'card' => [
            'shape'    => '-mb-px rounded-t-[10px] border border-b-0',
            'active'   => 'bg-surface-elevated text-brand font-bold border-hairline shadow-[inset_0_3px_0_#157547] dark:bg-surface-dark-elevated dark:text-brand-lime dark:border-gray-700 dark:shadow-[inset_0_3px_0_#A1CD3A]',
            'inactive' => 'border-transparent text-muted hover:bg-surface-soft dark:text-gray-400 dark:hover:bg-gray-800',
        ],
        'chip' => [
            'shape'    => 'rounded-full border',
            'active'   => 'bg-brand border-brand text-white font-semibold',
            'inactive' => 'bg-surface-elevated border-hairline text-muted hover:border-brand hover:text-brand dark:bg-gray-800 dark:border-gray-700 dark:text-gray-300 dark:hover:text-brand-lime dark:hover:border-brand-lime',
        ],
    ];

    $v = $map[$variant] ?? $map['underline'];
    $shape = $base . ' ' . $v['shape'];
@endphp

@if ($activeExpr !== null)
    {{-- Alpine-reactive: kelas aktif/nonaktif di-toggle client-side --}}
    <button role="tab" x-bind:aria-selected="({{ $activeExpr }}) ? 'true' : 'false'"
        x-bind:class="({{ $activeExpr }}) ? '{{ $v['active'] }}' : '{{ $v['inactive'] }}'"
        {{ $attributes->except('variant')->class([$shape])->merge(['type' => 'button']) }}>
        {{ $slot }}
    </button>
@else
    {{-- Server mode: aktif dari prop :active --}}
    <button role="tab" aria-selected="{{ $active ? 'true' : 'false' }}" {{ $attributes->except('variant')->class([
        $shape,
        $v['active'] => $active,
        $v['inactive'] => ! $active,
    ])->merge(['type' => 'button']) }}>
        {{ $slot }}
    </button>
@endif
