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
    // Status terlihat beda (UX): AKTIF = gaya SIRUS — latar gray-50 + garis gray-300 tipis (menyatu dengan latar),
    // hijau brand saat disorot/fokus;
    // NONAKTIF = latar abu lebih tegas + garis pudar, teks TETAP terbaca (text-body, 7,7:1), kursor dilarang; READ-ONLY (form terkunci) = latar abu + garis
    // putus-putus, teks tetap jelas. (Sebelumnya input aktif berlatar abu sehingga terlihat seperti nonaktif.)
    $baseClass = 'rounded-base disabled:bg-neutral-tertiary disabled:border-default disabled:text-body disabled:placeholder:text-gray-500 disabled:opacity-100 disabled:[-webkit-text-fill-color:currentColor] disabled:shadow-none disabled:cursor-not-allowed dark:disabled:bg-gray-700 dark:disabled:text-gray-300 enabled:read-only:bg-neutral-secondary-soft enabled:read-only:border-dashed enabled:read-only:shadow-none dark:enabled:read-only:bg-gray-800' . $lebarBawaan;

    $normalClass = 'bg-gray-50 text-heading border-gray-300 shadow-sm placeholder:text-gray-500 dark:bg-gray-900 dark:border-gray-700 dark:placeholder:text-gray-400
        enabled:hover:border-brand/60 focus:ring-brand/40 focus:border-brand dark:focus:ring-brand-lime/40 dark:focus:border-brand-lime transition-colors duration-150';

    $errorClass = 'bg-danger-soft text-fg-danger-strong border-danger-subtle placeholder:text-fg-danger-strong
        focus:ring-danger focus:border-danger';
@endphp

<textarea @disabled($disabled)
    {{ $attributes->merge([
        'class' => $error ? "$baseClass $errorClass" : "$baseClass $normalClass",
    ]) }}>{{ $slot }}</textarea>
