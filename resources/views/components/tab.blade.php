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
    - color     : warna aktif utk variant underline & pill — brand|emerald|rose|blue|purple|violet (default brand).
    - Atribut lain (wire:click, x-on:click, title, dll) diteruskan ke <button>.
--}}
@aware(['variant' => 'underline'])
@props([
    'active' => false,
    'activeExpr' => null,
    'color' => 'brand',
])

@php
    // variant: prop eksplisit di <x-tab> menang; kalau tidak ada, warisi dari <x-tabs> via @aware;
    // fallback 'underline'. ($variant ?? ...) aman walau @aware tak set (mis. <x-tab> tanpa induk <x-tabs>).
    $variant = $attributes->get('variant', $variant ?? 'underline');

    $base = 'px-4 py-2 text-title-sm font-medium whitespace-nowrap transition-colors focus:outline-none cursor-pointer';

    // warna aktif (hanya underline & pill yg colorable; card/chip selalu brand)
    $palette = [
        'underline' => [
            'brand'   => 'text-brand border-brand dark:text-brand-lime dark:border-brand-lime',
            'emerald' => 'text-emerald-700 border-emerald-600 dark:text-emerald-300 dark:border-emerald-400',
            'rose'    => 'text-rose-700 border-rose-600 dark:text-rose-300 dark:border-rose-400',
            'blue'    => 'text-blue-700 border-blue-600 dark:text-blue-300 dark:border-blue-400',
            'purple'  => 'text-purple-700 border-purple-600 dark:text-purple-300 dark:border-purple-400',
            'violet'  => 'text-violet-700 border-violet-600 dark:text-violet-300 dark:border-violet-400',
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
            'shape'    => '-mb-px border-b-2',
            'active'   => ($palette['underline'][$color] ?? $palette['underline']['brand']) . ' font-semibold',
            'inactive' => 'border-transparent text-muted hover:text-body dark:text-gray-400 dark:hover:text-gray-200',
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
    <button x-bind:class="({{ $activeExpr }}) ? '{{ $v['active'] }}' : '{{ $v['inactive'] }}'"
        {{ $attributes->except('variant')->class([$shape])->merge(['type' => 'button']) }}>
        {{ $slot }}
    </button>
@else
    {{-- Server mode: aktif dari prop :active --}}
    <button {{ $attributes->except('variant')->class([
        $shape,
        $v['active'] => $active,
        $v['inactive'] => ! $active,
    ])->merge(['type' => 'button']) }}>
        {{ $slot }}
    </button>
@endif
