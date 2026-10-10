@props([
    'type' => 'button',
    'color' => 'green', // green|lime|red|yellow|blue|gray
    'disabled' => false,
])

@php
    $colors = [
        'green' =>
            'text-brand-green border-brand-green/30 hover:bg-brand-green/10 focus:ring-brand-green/20 dark:text-brand-lime dark:border-brand-lime/30 dark:hover:bg-brand-lime/10 dark:focus:ring-brand-lime/20',
        'lime' => 'text-brand-lime border-brand-lime/40 hover:bg-brand-lime/10 focus:ring-brand-lime/20',
        'red' =>
            'text-red-600 border-red-300 hover:bg-red-50 focus:ring-red-200 dark:text-red-400 dark:border-red-500/30 dark:hover:bg-red-500/10 dark:focus:ring-red-900',
        'yellow' =>
            'text-yellow-600 border-yellow-300 hover:bg-yellow-50 focus:ring-yellow-200 dark:text-yellow-400 dark:border-yellow-500/30 dark:hover:bg-yellow-500/10 dark:focus:ring-yellow-900',
        'blue' =>
            'text-blue-600 border-blue-300 hover:bg-blue-50 focus:ring-blue-200 dark:text-blue-400 dark:border-blue-500/30 dark:hover:bg-blue-500/10 dark:focus:ring-blue-900',
        'gray' =>
            'text-gray-500 border-gray-300 hover:bg-gray-100 focus:ring-gray-200 dark:text-gray-400 dark:border-gray-600 dark:hover:bg-gray-700 dark:focus:ring-gray-700',
    ];
@endphp

<button type="{{ $type }}" {{ $disabled ? 'disabled' : '' }}
    {{ $attributes->class([
        // square — p-2 biar icon pas di tengah
        'inline-flex items-center justify-center',
        'p-2 rounded-lg',
        'text-sm',
        'border bg-transparent',
        'transition-colors duration-150',
        'focus:outline-none focus:ring-4',
    
        // color variant
        $colors[$color] ?? $colors['gray'],
    
        // disabled
        'disabled:opacity-50',
        'disabled:cursor-not-allowed',
        'disabled:pointer-events-none',
    ]) }}>
    {{ $slot }}
</button>
