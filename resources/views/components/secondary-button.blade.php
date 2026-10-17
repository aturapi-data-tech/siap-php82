@props([
    'type' => 'button',
    'disabled' => false,
])

<button type="{{ $type }}" {{ $disabled ? 'disabled' : '' }}
    {{ $attributes->class([
        // layout — Flowbite alternative: same size as primary
        'inline-flex items-center justify-center gap-2',
        'px-5 py-2.5 rounded-lg',
        'text-sm font-medium',
        'transition-colors duration-150',
    
        // v2 — light: permukaan elevated + garis hairline
        'text-body bg-surface-elevated',
        'border border-hairline',
        'hover:bg-surface-soft hover:text-ink',
        'focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-green focus-visible:ring-offset-2',

        // dark
        'dark:bg-surface-dark-elevated dark:text-gray-200',
        'dark:border-gray-700',
        'dark:hover:bg-gray-700 dark:hover:text-white',
        'dark:focus-visible:ring-brand-lime dark:focus-visible:ring-offset-gray-900',
    
        // disabled
        'disabled:opacity-50',
        'disabled:cursor-not-allowed',
        'disabled:pointer-events-none',
    ]) }}>
    {{ $slot }}
</button>
