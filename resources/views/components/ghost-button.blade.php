@props([
    'type' => 'button',
    'disabled' => false,
])

<button type="{{ $type }}" {{ $disabled ? 'disabled' : '' }}
    {{ $attributes->class([
        // layout — sama persis dengan primary & secondary
        'inline-flex items-center justify-center gap-2',
        'px-5 py-2.5 rounded-lg',
        'text-sm font-medium',
        'transition-colors duration-150',
    
        // light mode — subtle green tint with border
        'text-brand-green bg-brand-green/5',
        'border border-brand-green/20',
        'hover:bg-brand-green/10 hover:border-brand-green/30',
        'focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-green focus-visible:ring-offset-2',

        // dark mode
        'dark:text-brand-lime dark:bg-brand-lime/5',
        'dark:border-brand-lime/20',
        'dark:hover:bg-brand-lime/10 dark:hover:border-brand-lime/30',
        'dark:focus-visible:ring-brand-lime dark:focus-visible:ring-offset-gray-900',
    
        // disabled
        'disabled:opacity-50',
        'disabled:cursor-not-allowed',
        'disabled:pointer-events-none',
    ]) }}>
    {{ $slot }}
</button>
