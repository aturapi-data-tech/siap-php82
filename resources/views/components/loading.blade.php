@props(['size' => 'sm'])

@php
$sizes = ['xs' => 'w-3 h-3', 'sm' => 'w-4 h-4', 'md' => 'w-5 h-5', 'lg' => 'w-6 h-6'];
$sizeClass = $sizes[$size] ?? $sizes['sm'];
@endphp

<svg {{ $attributes->class(["inline animate-spin $sizeClass"]) }}
     fill="none" viewBox="0 0 24 24">
    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
</svg>
