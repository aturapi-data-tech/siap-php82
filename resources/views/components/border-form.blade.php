@props([
'title' => '',
'align' => 'start',
'bgcolor' => 'bg-canvas',
'class' => '',
'titleClass' => '',
'padding' => 'p-4',
'collapsible' => false,
'open' => true,
])

@php
// Set alignment class
$alignClass = match($align) {
'center' => 'text-center',
'end' => 'text-right',
'right' => 'text-right',
default => 'text-left',
};
@endphp

{{-- Model kartu outline standar UI ("Input teks & pilihan"):
     latar canvas + border hairline, judul = label uppercase (ds-form-title, warna ink)
     di atas, TANPA header bar terisi. --}}
<div {{ $attributes->merge(['class' => "border border-hairline rounded-2xl shadow-sm dark:border-gray-700 {$bgcolor} dark:bg-gray-900 {$class}"]) }}
    @if($collapsible) x-data="{ open: @js($open) }" @endif>
    <div class="{{ $padding }}">
        @if($title)
            @if($collapsible)
                <button type="button" @click="open = !open"
                    class="flex items-center justify-between w-full gap-2 mb-4">
                    <span class="ds-form-title {{ $alignClass }} {{ $titleClass }}">{{ $title }}</span>
                    <svg class="w-4 h-4 text-muted transition-transform shrink-0" :class="open ? 'rotate-180' : ''"
                        fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                    </svg>
                </button>
                <div x-show="open" x-collapse>
                    {{ $slot }}
                </div>
            @else
                <div class="ds-form-title mb-4 {{ $alignClass }} {{ $titleClass }}">{{ $title }}</div>
                {{ $slot }}
            @endif
        @else
            {{ $slot }}
        @endif
    </div>
</div>
