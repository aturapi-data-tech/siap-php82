@props([
    'label' => '',
    'value' => '',
    'checked' => false,
    'disabled' => false,
    'id' => null,
    'name' => null,
    'required' => false,
    'error' => false,
    'helpText' => null,
])

@php
    $id = $id ?? 'checkbox_' . uniqid();
@endphp

<div {{ $attributes->only(['class']) }}>
    <div class="flex items-center">
        <input type="checkbox" id="{{ $id }}" name="{{ $name ?? $id }}" value="{{ $value }}"
            @if ($checked) checked @endif @if ($disabled) disabled @endif
            @if ($required) required @endif
            {{ $attributes->except(['class'])->merge([
                'class' =>
                    'w-4 h-4 text-brand-green bg-gray-100 border-gray-300 rounded focus:ring-brand-green/50 focus:ring-2 dark:bg-gray-700 dark:border-gray-600 ' .
                    ($error ? 'border-red-500 dark:border-red-500' : ''),
            ]) }}>

        @if ($label)
            <label for="{{ $id }}"
                class="ml-2 text-sm font-medium text-gray-700 dark:text-gray-300 cursor-pointer {{ $disabled ? 'text-gray-400 dark:text-gray-500 cursor-not-allowed' : '' }}">
                {{ $label }}

                @if ($required)
                    <span class="text-red-500">*</span>
                @endif
            </label>
        @endif
    </div>

    @if ($helpText)
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            {{ $helpText }}
        </p>
    @endif
</div>
