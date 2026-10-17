{{-- Kartu hasil absen — dipakai Absen HP & Kiosk supaya tampilannya sama.
     Props: hasil = ['tipe' => masuk|pulang|info|error, 'judul', 'pesan', 'status' => H|T|…]. Slot = tombol / keterangan di bawah.
     Warna status dari token Flowbite-brand; status juga tertulis (judul + ikon), bukan warna saja. --}}
@props(['hasil'])

@php
    [$garis, $lingkaran, $ikon] = match ($hasil['tipe']) {
        'masuk' => $hasil['status'] === 'T'
            ? ['border-t-warning', 'bg-warning-soft text-fg-warning', 'jam']
            : ['border-t-success', 'bg-success-soft text-fg-success-strong', 'cek'],
        'pulang' => ['border-t-brand', 'bg-brand-softer text-fg-brand-strong', 'pulang'],
        'info' => ['border-t-default-medium', 'bg-neutral-secondary-medium text-heading', 'info'],
        default => ['border-t-danger', 'bg-danger-soft text-fg-danger-strong', 'larang'],
    };
@endphp

<div {{ $attributes->merge(['class' => "p-6 text-center border border-t-4 shadow-sm bg-canvas border-hairline rounded-3xl $garis"]) }}
    role="{{ $hasil['tipe'] === 'error' ? 'alert' : 'status' }}" aria-live="assertive">
    <span class="grid w-16 h-16 mx-auto mb-4 rounded-full place-items-center {{ $lingkaran }}">
        <x-absensi.ikon :nama="$ikon" class="w-8 h-8" />
    </span>
    <p class="text-2xl font-bold text-ink">{{ $hasil['judul'] }}</p>
    <p class="mt-2 text-lg leading-relaxed text-body">{{ $hasil['pesan'] }}</p>
    {{ $slot }}
</div>
