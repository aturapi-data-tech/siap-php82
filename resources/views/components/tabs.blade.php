{{--
    <x-tabs> — Tab bar Standar UI siRUS v2. Track + slot untuk <x-tab>.

    4 model, ganti lewat prop `variant`:
        pill      (A) — segmented pill, aktif solid di track abu-abu  ← standar utk filter/toggle sempit
        underline (B) — garis bawah, aktif teks+border berwarna       ← standar utk tab section konten (default existing)
        card      (C) — tab kartu/folder, aktif naik menyatu panel bawah
        chip      (D) — chip rounded-full lepas, bisa wrap

    Variant cukup ditulis di <x-tabs> — tiap <x-tab> mewarisinya via @aware:

        <x-tabs variant="underline">
            <x-tab :active="$activeTab === 'rj'"  color="emerald" wire:click="setTab('rj')">Rawat Jalan</x-tab>
            <x-tab :active="$activeTab === 'ugd'" color="rose"    wire:click="setTab('ugd')">UGD</x-tab>
        </x-tabs>

    Mode Alpine (aktif dihitung client-side, tanpa round-trip Livewire):
        <x-tabs variant="underline" class="flex-wrap p-2">
            @foreach ($menus as $m)
                <x-tab active-expr="tab === '{{ $m['id'] }}'" x-on:click="tab = '{{ $m['id'] }}'">{{ $m['name'] }}</x-tab>
            @endforeach
        </x-tabs>

    - Track default sengaja minimal (samakan look existing); tambah class via atribut: class="flex-wrap p-2".
    - Tab banyak & perlu scroll horizontal → bungkus dgn <x-scrollable-tabs>.
--}}
@props(['variant' => 'underline'])

@php
    $track = [
        'pill'      => 'inline-flex items-center gap-1 p-1 rounded-[10px] bg-surface-soft border border-hairline dark:bg-gray-800 dark:border-gray-700',
        'underline' => 'flex border-b border-hairline dark:border-gray-700',
        'card'      => 'flex items-end gap-1.5 px-2.5 border-b border-hairline dark:border-gray-700',
        'chip'      => 'flex flex-wrap gap-2',
    ][$variant] ?? 'flex border-b border-hairline dark:border-gray-700';
@endphp

<div {{ $attributes->class([$track]) }}>
    {{ $slot }}
</div>
