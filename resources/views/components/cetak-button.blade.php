{{--
    Tombol Cetak baku (seluruh repo) — meniru tombol biru di tabel Slip Gaji Dokter.

    Ikon saja (aksi per entri di tabel):
        <x-cetak-button wire:click="cetak('{{ $rowKey }}')" title="Cetak laporan" />
    Ikon + teks (tombol global / perlu keterangan):
        <x-cetak-button wire:click="cetakEresep" label="Cetak E-Resep" />
        <x-cetak-button wire:click="cetakKwitansi">Cetak Kwitansi</x-cetak-button>

    wire:target + spinner diambil otomatis dari wire:click (timpa lewat :target bila perlu).
    title default = label, atau "Cetak" bila ikon saja.
--}}
@props([
    'title' => null,
    'target' => null,
    'label' => null,
])

@php
    $target = $target ?? ($attributes->get('wire:click') ?? '');
    $teks = trim((string) ($label ?? $slot));
    $title = $title ?? ($teks !== '' ? $teks : 'Cetak');
    // p-2.5 + ikon w-5 h-5 = tinggi sama dengan tombol berteks di sebelahnya.
    $kelas = $teks !== '' ? '!px-3 !py-2.5 gap-2 shrink-0 whitespace-nowrap' : '!p-2.5 shrink-0';
@endphp

<x-icon-button color="blue" {{ $attributes->merge(['class' => $kelas]) }} wire:loading.attr="disabled"
    wire:target="{{ $target }}" title="{{ $title }}">
    <span wire:loading.remove wire:target="{{ $target }}" class="inline-flex items-center gap-2">
        <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8">
            <path stroke-linecap="round" stroke-linejoin="round"
                d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0110.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0l.229 2.523a1.125 1.125 0 01-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.318 0h1.091A2.25 2.25 0 0021 15.75V9.456c0-1.081-.768-2.015-1.837-2.175a48.055 48.055 0 00-1.913-.247M6.34 18H5.25A2.25 2.25 0 013 15.75V9.456c0-1.081.768-2.015 1.837-2.175a48.041 48.041 0 011.913-.247m10.5 0a48.536 48.536 0 00-10.5 0m10.5 0V3.375c0-.621-.504-1.125-1.125-1.125h-8.25c-.621 0-1.125.504-1.125 1.125v3.659M18 10.5h.008v.008H18V10.5z" />
        </svg>
        @if ($teks !== '')
            <span>{{ $teks }}</span>
        @endif
    </span>
    <span wire:loading wire:target="{{ $target }}" class="inline-flex items-center gap-2">
        <x-loading size="md" />
        @if ($teks !== '')
            <span>Menyiapkan...</span>
        @endif
    </span>
</x-icon-button>
