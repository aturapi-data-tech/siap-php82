@props([
    // Nama Livewire property yang di-bind (required).
    'name',
    'placeholder' => 'Tulis di sini…',
    'height' => 480,
    // Modal name untuk re-init saat modal open (kalau editor di dalam modal).
    'modalEvent' => null,
    // Custom event window untuk paksa flush isi ke $wire.
    'flushEvent' => null,
    // Custom event untuk RELOAD isi dari $wire ke editor (mis. setelah server-side reset).
    'reloadEvent' => null,
    // CSS tambahan utk content editor (WYSIWYG) — di-append ke content_style TinyMCE.
    // Mis. supaya tampilan editor mirror tema cetak (font/border/warna).
    'contentStyle' => null,
])

@php
    if (empty($name)) {
        throw new \InvalidArgumentException('<x-tinymce-editor> wajib menerima prop "name" (nama Livewire property).');
    }
@endphp

<div
    wire:ignore
    x-data="tinymceEditor({
        propName: @js($name),
        placeholder: @js($placeholder),
        modalEvent: @js($modalEvent),
        flushEvent: @js($flushEvent),
        reloadEvent: @js($reloadEvent),
        height: {{ (int) $height }},
        contentStyle: @js($contentStyle),
    })"
    {{ $attributes->merge(['class' => 'bg-white dark:bg-gray-900 rounded-md']) }}
>
    <textarea x-ref="host"></textarea>
</div>
