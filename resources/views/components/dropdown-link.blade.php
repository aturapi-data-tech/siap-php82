{{-- Flowbite v4 dropdown item. bahaya = aksi keluar/hapus (merah) — warna dipilih di sini, bukan ditimpa
     lewat class="...", karena text-body akan menang urutan CSS atas text-fg-danger-strong. --}}
@props(['bahaya' => false])

@php
    $warna = $bahaya
        ? 'text-fg-danger-strong hover:bg-danger-soft focus:bg-danger-soft'
        : 'text-body hover:bg-neutral-tertiary-medium hover:text-heading focus:bg-neutral-tertiary-medium dark:text-gray-300 dark:hover:text-white';
@endphp

<a {{ $attributes->merge(['class' => "flex items-center gap-2 w-full p-2 text-start text-sm font-medium rounded focus:outline-none transition-colors duration-150 $warna"]) }}>{{ $slot }}</a>
