@props([
    'type' => 'submit',
    'disabled' => false,
])

<button type="{{ $type }}" {{ $disabled ? 'disabled' : '' }}
    {{ $attributes->class([
        // Flowbite v4 (warna = token brand di tailwind.config.cjs / app.css --fb-*)
        'inline-flex items-center justify-center gap-2 box-border border',
        'px-4 py-2.5 rounded-base shadow-xs',
        'text-sm font-medium leading-5',
        'transition-colors duration-150',
        'focus:outline-none focus:ring-4',

        // Default (brand): hijau Madinah — mode gelap lime + teks gelap (putih di atas lime 1,9:1)
        'text-white bg-brand border-transparent hover:bg-brand-strong focus:ring-brand-medium',
        'dark:bg-brand-lime dark:text-ink dark:hover:bg-brand-lime/90 dark:focus:ring-offset-2 dark:focus:ring-offset-gray-900',

        // disabled
        'disabled:opacity-50',
        'disabled:cursor-not-allowed',
        'disabled:pointer-events-none',
    ]) }}>
    {{ $slot }}
</button>
