@props([
    'type' => 'submit',
    'disabled' => false,
])

<button type="{{ $type }}" {{ $disabled ? 'disabled' : '' }}
    {{ $attributes->class([
        // layout — Flowbite: px-5 py-2.5 rounded-lg text-sm font-medium
        'inline-flex items-center justify-center gap-2',
        'px-5 py-2.5 rounded-lg',
        'text-sm font-medium',
        'transition-colors duration-150',
    
        // light mode
        'text-white bg-brand-green',
        'hover:bg-brand-green/90',
        'focus:outline-none focus:ring-4 focus:ring-brand-lime/40',
    
        // dark mode
        'dark:bg-brand-lime dark:text-gray-900',
        'dark:hover:bg-brand-lime/90',
        'dark:focus:ring-brand-green/40',
    
        // disabled
        'disabled:opacity-50',
        'disabled:cursor-not-allowed',
        'disabled:pointer-events-none',
    ]) }}>
    {{ $slot }}
</button>
