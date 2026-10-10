@props([
    'disabled' => false,
    'error' => false,
])

@php
    // Tanpa text-sm bawaan — ukuran font ikut x-text-input (text-base) supaya
    // TINGGI select = tinggi text input saat bersanding di toolbar/form.
    // Pemakai yang butuh font kecil tetap bisa pass class="text-sm".
    //
    // Border & focus-ring dipisah dari $baseClass supaya saat error tidak bentrok
    // dgn border-gray (border-gray-300 ada SETELAH border-error di CSS build → gray menang).
    $baseClass = 'block w-full rounded-lg bg-gray-50 text-gray-900 shadow-sm
        disabled:opacity-90 disabled:bg-gray-100 disabled:cursor-not-allowed
        dark:bg-gray-900 dark:text-gray-100';

    $normalClass = 'border-gray-300 focus:border-brand-green focus:ring-brand-green/40
        dark:border-gray-700 dark:focus:border-brand-lime dark:focus:ring-brand-lime/40';

    $errorClass = 'border-error focus:border-error focus:ring-error/40
        dark:border-error dark:focus:border-error dark:focus:ring-error/40';
@endphp

<select {{ $disabled ? 'disabled' : '' }} {!! $attributes->merge([
    'class' => $error ? "$baseClass $errorClass" : "$baseClass $normalClass",
]) !!}>
    {{ $slot }}
</select>
