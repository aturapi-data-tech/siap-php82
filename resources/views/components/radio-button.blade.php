@props([
    'disabled' => false,
    'label' => 'Label',
    'value' => '',
    'name' => '',
    'checked' => false, // Tambahkan prop untuk mengecek selected state
    'wire' => null, // Untuk mendukung wire:model
])

@php
    // Cek apakah radio button ini yang dipilih
    $isChecked = $checked || old($name) == $value;

    $containerClasses =
        'flex items-center p-3 border rounded-lg cursor-pointer transition-colors duration-200 ' .
        ($disabled
            ? 'opacity-50 cursor-not-allowed bg-gray-50 dark:bg-gray-800/50 border-gray-200 dark:border-gray-700'
            : 'hover:bg-gray-50 dark:hover:bg-gray-800 border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900');

    $inputClasses =
        'w-4 h-4 text-brand focus:ring-brand dark:bg-gray-800 dark:border-gray-600 ' .
        ($disabled ? 'cursor-not-allowed' : 'cursor-pointer');

    $labelClasses =
        'ml-2 text-sm text-gray-700 dark:text-gray-300 ' . ($disabled ? 'cursor-not-allowed' : 'cursor-pointer');

    // Tambahkan class untuk state error jika diperlukan
    $hasError = $errors->has($name);
    if ($hasError && !$disabled) {
        $containerClasses .= ' border-red-300 dark:border-red-700';
        $inputClasses .= ' border-red-500 text-red-600 focus:ring-red-500';
    }
@endphp

<label for="{{ $name }}_{{ Str::slug($value) }}" class="{{ $containerClasses }}"
    @if ($disabled) aria-disabled="true" @endif>

    <input type="radio" id="{{ $name }}_{{ Str::slug($value) }}" name="{{ $name }}"
        value="{{ $value }}" {{ $disabled ? 'disabled' : '' }} @if ($isChecked) checked @endif
        {{ $attributes->merge(['class' => $inputClasses]) }} {{-- Dukungan untuk Livewire 4 --}}
        @if ($wire) wire:model="{{ $wire }}"
        @elseif($attributes->has('wire:model'))
            {{-- Biarkan wire:model dari atribut --}}
        @elseif(!$disabled)
            wire:model.live="{{ $name }}" @endif>

    <span class="{{ $labelClasses }}">
        {{ $label }}
    </span>
</label>
