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
    
        // light mode — yellow solid, dark text
        'text-white bg-yellow-500',
        'hover:bg-yellow-600',
        'focus:outline-none focus:ring-4 focus:ring-yellow-300',
    
        // dark mode
        'dark:bg-yellow-500',
        'dark:hover:bg-yellow-600',
        'dark:focus:ring-yellow-900',
    
        // disabled
        'disabled:opacity-50',
        'disabled:cursor-not-allowed',
        'disabled:pointer-events-none',
    ]) }}>
    {{ $slot }}
</button>
