@props([
    'active' => false,
])

<button type="button"
    {{ $attributes->merge([
        'class' => collect([
            // base
            'w-full px-4 py-3 text-left rounded-lg transition-colors duration-150',
    
            // text
            'text-gray-800 dark:text-gray-100',
    
            // hover
            'hover:bg-brand-lime/10 dark:hover:bg-brand-lime/20',
    
            // active (keyboard / selected)
            $active ? 'bg-brand-lime/15 dark:bg-brand-lime/25 ring-1 ring-brand-lime/30' : '',
    
            // focus (accessibility)
            'focus:outline-none focus:ring-2 focus:ring-brand-lime/40',
        ])->implode(' '),
    ]) }}>
    {{ $slot }}
</button>
