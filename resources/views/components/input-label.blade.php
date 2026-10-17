@props([
    'value' => null,
    'required' => false,
])

<label
    {{ $attributes->merge([
        'class' => 'block text-sm font-medium text-heading',
    ]) }}>
    {{ $value ?? $slot }}@if ($required)<span class="text-fg-danger-strong" title="Wajib diisi"> *</span>@endif
</label>
