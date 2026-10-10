@props([
    'type' => 'button',
    'disabled' => false,
    'title' => 'Set ke waktu sekarang',
])

{{-- Tombol standar "Sekarang": icon jam, hemat tempat. Pakai untuk semua aksi setTgl* --}}
<button type="{{ $type }}" {{ $disabled ? 'disabled' : '' }} title="{{ $title }}"
    {{ $attributes->class([
        'inline-flex items-center justify-center shrink-0',
        'p-2.5 rounded-lg',
        'transition-colors duration-150',
        // selaras dengan secondary-button (gray)
        'text-gray-700 bg-gray-100 border border-gray-300',
        'hover:bg-gray-200 hover:text-gray-900',
        'focus:outline-none focus:ring-4 focus:ring-gray-200',
        'dark:bg-gray-800 dark:text-gray-200 dark:border-gray-600',
        'dark:hover:bg-gray-700 dark:hover:text-white dark:focus:ring-gray-700',
        'disabled:opacity-50 disabled:cursor-not-allowed disabled:pointer-events-none',
    ]) }}>
    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
    </svg>
    <span class="sr-only">{{ $title }}</span>
</button>
