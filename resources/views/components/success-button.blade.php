@props([
    'type' => 'button',
    'disabled' => false,
])

<button type="{{ $type }}" {{ $disabled ? 'disabled' : '' }}
    {{ $attributes->class([
        'inline-flex items-center justify-center gap-2',
        'px-5 py-2.5 rounded-lg',
        'text-sm font-medium',
        'transition-colors duration-150',
    
        // light mode — brand-lime solid, dark text
        'text-gray-900 bg-brand-lime',
        'hover:bg-brand-lime/90',
        'focus:outline-none focus:ring-4 focus:ring-brand-lime/40',
    
        // dark mode
        'dark:bg-brand-lime',
        'dark:text-gray-900',
        'dark:hover:bg-brand-lime/90',
        'dark:focus:ring-brand-lime/30',
    
        // disabled
        'disabled:opacity-50',
        'disabled:cursor-not-allowed',
        'disabled:pointer-events-none',
    ]) }}>
    {{ $slot }}
</button>
