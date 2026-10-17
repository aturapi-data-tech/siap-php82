{{-- resources/views/components/toggle.blade.php --}}
{{--
    Pemakaian:
      Mode 1 (model binding):
        <x-toggle wire:model.live="passStatus" trueValue="N" falseValue="O" label="Pasien Baru" :disabled="$isFormLocked" />
        <x-toggle wire:model.live="dataDaftarUGD.passStatus" trueValue="N" falseValue="O" label="Pasien Baru" />
        <x-toggle wire:model.live="activeStatus" trueValue="1" falseValue="0">Status Aktif</x-toggle>
        <x-toggle wire:model.live="forceOccupiedBed" :trueValue="true" :falseValue="false" label="Paksa pilih bed" />
        <x-toggle wire:model="cito" trueValue="1" falseValue="0" onColor="bg-error" label="CITO" />

      Mode 2 (per-row di table — pakai `current` + `wireClick`):
        <x-toggle :current="$row->active_record" trueValue="1" falseValue="0"
                  wireClick="toggleActive('{{ $row->emp_id }}')">Aktif</x-toggle>

    Catatan:
      - Pure server-side render (tanpa Alpine local state) — bebas race condition
      - Mendukung nested array (dataDaftarXxx.field) tanpa @entangle
      - Click memicu wire:click="$set(...)" (Mode 1) atau wire:click="..." (Mode 2)
--}}

@props([
    'trueValue' => 'Y',
    'falseValue' => 'N',
    'label' => null,
    'disabled' => false,
    'current' => null,    // override initial value (Mode 2 / per-row)
    'wireClick' => null,  // panggil method server saat klik (Mode 2)
    'onColor' => 'bg-brand', // warna track saat ON — mis. 'bg-error' utk penanda mendesak (CITO)
])

@php
    // Ambil nama model dari wire:model
    $wireModel = $attributes->whereStartsWith('wire:model')->first();

    // Initial value: prioritas $current → wire:model lookup → falseValue
    $currentValue = null;
    if ($current !== null) {
        $currentValue = $current;
    } elseif ($wireModel && isset($__livewire)) {
        try {
            $currentValue = data_get($__livewire, $wireModel);
        } catch (\Throwable) {
        }
    }
    $currentValue ??= $falseValue;

    $isOn = $currentValue == $trueValue;
    $newValue = $isOn ? $falseValue : $trueValue;

    // Encode value untuk wire:click="$set(...)" expression
    $encode = fn($v) => match (true) {
        is_bool($v)    => $v ? 'true' : 'false',
        is_int($v),
        is_float($v)   => (string) $v,
        is_null($v)    => 'null',
        default        => "'" . addslashes((string) $v) . "'",
    };

    // Strip wire:model — kita handle sendiri via wire:click
    $attrs = $attributes->whereDoesntStartWith('wire:model');
@endphp

{{-- <button role="switch">: bisa difokus dengan Tab & diubah dengan Spasi/Enter, terbaca pembaca layar sebagai sakelar. --}}
<button type="button" role="switch" aria-checked="{{ $isOn ? 'true' : 'false' }}" @disabled($disabled)
    @if (!$disabled)
        @if ($wireModel)
            wire:click="$set('{{ $wireModel }}', {{ $encode($newValue) }})"
        @elseif ($wireClick)
            wire:click="{{ $wireClick }}"
        @endif
    @endif
    class="inline-flex items-center gap-3 text-left rounded-full focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-medium {{ $disabled ? 'cursor-not-allowed' : 'cursor-pointer' }}"
    {{ $attrs }}>
    {{-- Flowbite v4 toggle: jalur w-9 h-5, kenop 16px; mati = neutral-quaternary (≥3:1 terhadap latar) --}}
    <span class="relative block w-9 h-5 transition-colors rounded-full shrink-0
        {{ $disabled ? 'bg-neutral-tertiary' : ($isOn ? $onColor : 'bg-neutral-quaternary') }}">
        <span class="absolute top-[2px] start-[2px] block w-4 h-4 transition-transform bg-white rounded-full shadow-xs motion-reduce:transition-none
            {{ $isOn ? 'translate-x-4' : 'translate-x-0' }}">
        </span>
    </span>

    <span class="block text-sm font-medium select-none {{ $disabled ? 'text-fg-disabled' : 'text-heading' }}">{{ $label ?? $slot }}</span>
</button>
