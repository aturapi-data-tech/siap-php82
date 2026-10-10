@props([
    'variant' => 'gray', // brand | alternative | gray | danger | success | warning | info | purple
])

@php
    $variants = [
        // "brand" pakai hijau brand asli (bukan emerald)
        'brand' => 'bg-brand-green/10 text-brand-green dark:bg-brand-lime/15 dark:text-brand-lime',
        'alternative' => 'bg-slate-100 text-slate-800 dark:bg-slate-800 dark:text-slate-200',
        'gray' => 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-200',
        // v2: status pakai -tint (bg) + -deep (teks) untuk kontras & selaras brand
        'danger' => 'bg-error-tint text-error-deep dark:bg-red-900/30 dark:text-red-200',
        'success' => 'bg-success-tint text-success-deep dark:bg-green-900/30 dark:text-green-200',
        'warning' => 'bg-warning-tint text-warning-deep dark:bg-amber-900/30 dark:text-amber-200',
        'info' => 'bg-info-tint text-info-deep dark:bg-blue-900/30 dark:text-blue-200',
        'purple' => 'bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-200',
    ];

    $classes = $variants[$variant] ?? $variants['gray'];
@endphp

<span
    {{ $attributes->merge([
        'class' => $classes . ' inline-flex items-center px-2 py-0.5 rounded text-xs font-medium',
    ]) }}>
    {{ $slot }}
</span>
