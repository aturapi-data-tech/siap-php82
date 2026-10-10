@props([
    'disabled' => false,
    'error' => false,
    'mou_label' => 'SetMou',
])

@php
    $baseClass = 'border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100
        focus:border-brand-lime focus:ring-brand-lime focus:ring-1 focus:outline-none
        shadow-sm disabled:opacity-90 disabled:bg-gray-100 disabled:cursor-not-allowed transition duration-150';

    $errorClass = 'border-red-500 focus:border-red-500 focus:ring-red-500
        dark:border-red-400 dark:focus:border-red-400 dark:focus:ring-red-400';

    $inputClass = $baseClass . ' rounded-l-lg border-r-0 w-full px-3 py-2';
    $mouClass =
        $baseClass .
        ' rounded-r-lg border-l-0 w-20 bg-gray-100 dark:bg-gray-800
        text-sm font-semibold text-center text-gray-700 dark:text-gray-300 px-2 py-1.5';
@endphp

<div class="flex w-full">
    <input @disabled($disabled)
        {{ $attributes->merge([
            'class' => $error ? "$inputClass $errorClass" : $inputClass,
        ]) }}>

    <input disabled class="{{ $mouClass }}" value="{{ $mou_label }}">
</div>
