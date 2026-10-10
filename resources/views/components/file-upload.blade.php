@props([
    'name',
    'label' => null,
    'accept' => null,
    'required' => false,
    'disabled' => false,
    'loadingText' => 'Memuat file...',
    'loadingStyle' => 'text',
    'showError' => true,
])

{{--
    PROPS:
    - name (required)  : Nama Livewire property untuk wire:model
    - label            : Label di atas input (null = tidak dirender)
    - accept           : MIME types untuk attribute `accept` di file input
    - required         : Tampilkan asterisk merah di label
    - disabled         : Propagate ke input (mis. form-locked)
    - loadingText      : Teks saat upload (untuk loadingStyle = text)
    - loadingStyle     : 'text' atau 'bar' (progress bar tipis)
    - showError        : Auto render error inline. Set false bila parent
                         sudah punya display error tersendiri di grid.
--}}

@php
    if (empty($name)) {
        throw new \InvalidArgumentException('<x-file-upload> wajib menerima prop "name" (nama Livewire property).');
    }

    $hasError = $errors->has($name);
@endphp

<div>
    @if (!empty($label))
        <x-input-label :value="$label" :required="$required" />
    @endif

    <input
        type="file"
        wire:model="{{ $name }}"
        @if ($accept) accept="{{ $accept }}" @endif
        @disabled($disabled)
        {{ $attributes->merge([
            'class' => 'block w-full mt-1 text-sm text-gray-600 file:mr-3 file:py-2 file:px-4 file:rounded file:border-0 file:text-sm file:font-semibold file:bg-brand-green/10 file:text-brand-green hover:file:bg-brand-green/20 disabled:opacity-60 disabled:cursor-not-allowed' . ($hasError ? ' ring-1 ring-red-500 rounded-md' : ''),
        ]) }}
    />

    @if ($loadingStyle === 'bar')
        <div wire:loading wire:target="{{ $name }}" class="mt-1 h-1 w-full bg-brand-green/30 rounded-full overflow-hidden">
            <div class="h-1 bg-brand-green animate-pulse rounded-full w-full"></div>
        </div>
    @else
        <div wire:loading wire:target="{{ $name }}" class="mt-2 text-xs text-gray-500">
            {{ $loadingText }}
        </div>
    @endif

    @if ($showError)
        @error($name)
            <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
        @enderror
    @endif
</div>
