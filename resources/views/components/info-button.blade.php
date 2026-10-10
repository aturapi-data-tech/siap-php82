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
    
        // light mode — blue solid
        'text-white bg-blue-600',
        'hover:bg-blue-700',
        'focus:outline-none focus:ring-4 focus:ring-blue-300',
    
        // dark mode
        'dark:bg-blue-600',
        'dark:hover:bg-blue-700',
        'dark:focus:ring-blue-900',
    
        // disabled
        'disabled:opacity-50',
        'disabled:cursor-not-allowed',
        'disabled:pointer-events-none',
    ]) }}>
    {{ $slot }}
</button>
