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
    
        // light mode — kuning token warning, teks gelap (putih di atas kuning hanya 1,9:1 — gagal WCAG AA)
        'text-gray-900 bg-warning',
        'hover:bg-warning/90',
        'focus:outline-none focus-visible:ring-2 focus-visible:ring-warning-deep focus-visible:ring-offset-2',
    
        // dark mode
        'dark:bg-warning',
        'dark:hover:bg-warning/90',
        'dark:focus-visible:ring-warning dark:focus-visible:ring-offset-gray-900',
    
        // disabled
        'disabled:opacity-50',
        'disabled:cursor-not-allowed',
        'disabled:pointer-events-none',
    ]) }}>
    {{ $slot }}
</button>
