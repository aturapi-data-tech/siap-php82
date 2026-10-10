@props([
    'type' => 'button',
    'disabled' => false,
])

<button type="{{ $type }}" {{ $disabled ? 'disabled' : '' }}
    {{ $attributes->class([
        // layout — sama persis dengan semua button lainnya
        'inline-flex items-center justify-center gap-2',
        'px-5 py-2.5 rounded-lg',
        'text-sm font-medium',
        'transition-colors duration-150',
    
        // v2 — token error solid, hover error-deep
        'text-white bg-error',
        'hover:bg-error-deep',
        'focus:outline-none focus:ring-4 focus:ring-error/30',

        // dark mode
        'dark:bg-error',
        'dark:hover:bg-error-deep',
        'dark:focus:ring-error/40',
    
        // disabled
        'disabled:opacity-50',
        'disabled:cursor-not-allowed',
        'disabled:pointer-events-none',
    ]) }}>
    {{ $slot }}
</button>
