@props([
    'disabled' => false,
    'error' => false,
])

@php
    // Flowbite v4 input: bg-neutral-secondary-medium, border-default-medium, text-heading, rounded-base,
    // shadow-xs, focus ring/border brand; galat = latar danger-soft + teks fg-danger-strong.
    // Latar, teks & border SENGAJA hanya di $normalClass / $errorClass (tidak di $baseClass): kalau dua kelas
    // satu jenis ikut bersamaan, urutan CSS build yang menang — bukan maksud kita.
    // Padding & ukuran font dibiarkan bawaan @tailwindcss/forms (halaman banyak menimpa lewat class="...").
    // w-full hanya bila pemakai tidak memberi lebar sendiri (class="w-28" dst.) — kalau ikut, w-full menang
    // urutan CSS dan lebar pemakai diabaikan (input bulan jadi selebar kolom).
    $lebarBawaan = preg_match('/(^|\s)(?:[a-z0-9]+:)*!?w-/', (string) $attributes->get('class')) ? '' : ' w-full';
    $baseClass = 'block rounded-base shadow-xs disabled:text-fg-disabled disabled:cursor-not-allowed' . $lebarBawaan;

    $normalClass = 'bg-neutral-secondary-medium text-heading border-default-medium placeholder:text-gray-500 dark:placeholder:text-gray-400
        focus:ring-brand focus:border-brand';

    $errorClass = 'bg-danger-soft text-fg-danger-strong border-danger-subtle placeholder:text-fg-danger-strong
        focus:ring-danger focus:border-danger';
@endphp

<select {{ $disabled ? 'disabled' : '' }} {!! $attributes->merge([
    'class' => $error ? "$baseClass $errorClass" : "$baseClass $normalClass",
]) !!}>
    {{ $slot }}
</select>
