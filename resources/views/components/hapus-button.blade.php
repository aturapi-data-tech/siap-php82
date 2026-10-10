{{--
    Tombol Hapus baku (per entri di tabel/daftar) — sekelas x-cetak-button: ikon tong sampah
    merah, tinggi 40px sama dengan tombol berteks (p-2.5 + ikon w-5 h-5).

    Ikon saja:
        <x-hapus-button wire:click="hapus('{{ $rowKey }}')" confirm="Yakin hapus entri ini?" />
    Ikon + teks bila perlu:
        <x-hapus-button wire:click="hapus('{{ $rowKey }}')" label="Hapus" />

    confirm → wire:confirm (dialog bawaan browser). wire:target + spinner otomatis dari wire:click.
    title default = label, atau "Hapus".

    Dialog konfirmasi MODAL (bukan dialog browser) — pakai prop action, bukan wire:click:
        <x-hapus-button action="hapusBaris({{ $indeks }})" title="Hapus Baris" message="Baris akan dihapus. Lanjutkan?" />
    (dirender lewat x-confirm-button varian danger-soft, ukuran tetap 40px.)
--}}
@props([
    'title' => null,
    'target' => null,
    'label' => null,
    'confirm' => null,
    'action' => null,
    'message' => 'Entri akan dihapus. Lanjutkan?',
    'confirmText' => 'Ya, Hapus',
])

@php
    $target = $target ?? ($attributes->get('wire:click') ?? $attributes->get('wire:click.prevent') ?? '');
    $teks = trim((string) ($label ?? $slot));
    $title = $title ?? ($teks !== '' ? $teks : 'Hapus');
    $kelas = $teks !== '' ? '!px-3 !py-2.5 gap-2 shrink-0 whitespace-nowrap' : '!p-2.5 shrink-0';
    $ikonSampah = '<svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" /></svg>';
@endphp

@if ($action)
    <x-confirm-button variant="danger-soft" :action="$action" :title="$title" :message="$message" :confirmText="$confirmText"
        {{ $attributes->merge(['class' => $kelas]) }}>
        {!! $ikonSampah !!}
        @if ($teks !== '')
            <span>{{ $teks }}</span>
        @endif
    </x-confirm-button>
@else
{{-- wire:confirm lewat attribute bag: directive kondisional di dalam tag komponen merusak kompilasi Blade. --}}
<x-icon-button color="red" {{ $attributes->merge(['class' => $kelas] + ($confirm ? ['wire:confirm' => $confirm] : [])) }}
    wire:loading.attr="disabled" wire:target="{{ $target }}" title="{{ $title }}">
    <span wire:loading.remove wire:target="{{ $target }}" class="inline-flex items-center gap-2">
        <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8">
            <path stroke-linecap="round" stroke-linejoin="round"
                d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
        </svg>
        @if ($teks !== '')
            <span>{{ $teks }}</span>
        @endif
    </span>
    <span wire:loading wire:target="{{ $target }}" class="inline-flex items-center gap-2">
        <x-loading size="md" />
        @if ($teks !== '')
            <span>Menghapus...</span>
        @endif
    </span>
</x-icon-button>
@endif
