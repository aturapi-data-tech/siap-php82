{{-- Logo instansi — dari Sistem › Identitas Instansi (App\Support\Identitas). Ada logo → nama disembunyikan (sudah tertulis di logo); tanpa logo → nama. --}}
@php $logoUrl = \App\Support\Identitas::logoUrl(); $namaInstansi = \App\Support\Identitas::get()->nama; @endphp
<div class="flex flex-col items-center gap-2">
    @if ($logoUrl)
        <img src="{{ $logoUrl }}" alt="{{ $namaInstansi }}" class="object-contain w-auto h-20">
    @else
        <span class="text-sm font-semibold text-center text-ink dark:text-gray-100">{{ $namaInstansi }}</span>
    @endif
</div>
