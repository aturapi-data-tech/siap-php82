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
                    'w-4 h-4 text-brand bg-neutral-secondary-medium border-default-medium rounded-sm focus:ring-2 focus:ring-brand-medium ' .
                    ($error ? 'border-danger' : ''),
            ]) }}>

        @if ($label)
            <label for="{{ $id }}"
                class="select-none ms-2 text-sm font-medium cursor-pointer {{ $disabled ? 'text-fg-disabled cursor-not-allowed' : 'text-heading' }}">
                {{ $label }}

                @if ($required)
                    <span class="text-fg-danger-strong">*</span>
                @endif
            </label>
        @endif
    </div>

    @if ($helpText)
        <p class="mt-1 text-sm text-body dark:text-gray-400">
            {{ $helpText }}
        </p>
    @endif
</div>
