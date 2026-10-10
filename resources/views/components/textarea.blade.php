@props([
    'disabled' => false,
    'error' => false,
])

@php
    // Border & focus-ring dipisah dari $baseClass supaya saat error tidak
    // bentrok dgn border-gray (di CSS hasil build, border-gray-300 ada SETELAH
    // border-error → kalau dua-duanya ada, gray menang & border merah hilang).
    $baseClass = 'bg-gray-50 text-gray-900 dark:bg-gray-900 dark:text-gray-100
        rounded-lg shadow-sm disabled:opacity-90 disabled:bg-gray-100 disabled:cursor-not-allowed w-full';

    // v2: fokus ring brand (green) di terang, lime di gelap
    $normalClass = 'border-gray-300 dark:border-gray-700
        focus:border-brand-green focus:ring-brand-green/40
        dark:focus:border-brand-lime dark:focus:ring-brand-lime/40';

    $errorClass = 'border-error focus:border-error focus:ring-error/40
        dark:border-error dark:focus:border-error dark:focus:ring-error/40';
@endphp

<textarea @disabled($disabled)
    {{ $attributes->merge([
        'class' => $error ? "$baseClass $errorClass" : "$baseClass $normalClass",
    ]) }}>{{ $slot }}</textarea>
