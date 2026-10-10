{{--
    Tombol Lihat baku (per entri di tabel/daftar) — sekelas x-cetak-button & x-hapus-button:
    ikon mata abu-abu, tinggi 40px (p-2.5 + ikon w-5 h-5).

        <x-lihat-button wire:click="viewEntry('{{ $rowKey }}')" />
        <x-lihat-button wire:click="lihat('{{ $id }}')" label="Lihat PDF" />   (ikon + teks bila perlu)

    wire:target + spinner otomatis dari wire:click. title default = label, atau "Lihat".
--}}
@props([
    'title' => null,
    'target' => null,
    'label' => null,
])

@php
    $target = $target ?? ($attributes->get('wire:click') ?? '');
    $teks = trim((string) ($label ?? $slot));
    $title = $title ?? ($teks !== '' ? $teks : 'Lihat');
    $kelas = $teks !== '' ? '!px-3 !py-2.5 gap-2 shrink-0 whitespace-nowrap' : '!p-2.5 shrink-0';
@endphp

<x-icon-button color="gray" {{ $attributes->merge(['class' => $kelas]) }} wire:loading.attr="disabled"
    wire:target="{{ $target }}" title="{{ $title }}">
    <span wire:loading.remove wire:target="{{ $target }}" class="inline-flex items-center gap-2">
        <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8">
            <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
        </svg>
        @if ($teks !== '')
            <span>{{ $teks }}</span>
        @endif
    </span>
    <span wire:loading wire:target="{{ $target }}" class="inline-flex items-center gap-2">
        <x-loading size="md" />
        @if ($teks !== '')
            <span>Membuka...</span>
        @endif
    </span>
</x-icon-button>
