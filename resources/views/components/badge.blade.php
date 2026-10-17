@props([
    'variant' => 'gray', // brand | alternative | gray | danger | success | warning | info | purple
])

@php
    // Flowbite v4 badge — warna token brand/semantik (mode gelap ikut --fb-*)
    $variants = [
        'brand' => 'bg-brand-softer text-fg-brand-strong',
        'alternative' => 'bg-neutral-primary-soft text-heading border border-default',
        'gray' => 'bg-neutral-secondary-medium text-heading',
        'danger' => 'bg-danger-soft text-fg-danger-strong',
        'success' => 'bg-success-soft text-fg-success-strong',
        'warning' => 'bg-warning-soft text-fg-warning',
        'info' => 'bg-info-tint text-info-deep dark:bg-blue-900/30 dark:text-blue-200',
        'purple' => 'bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-200',
    ];

    $classes = $variants[$variant] ?? $variants['gray'];
@endphp

<span
    {{ $attributes->merge([
        'class' => $classes . ' inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium',
    ]) }}>
    {{ $slot }}
</span>
