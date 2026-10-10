{{-- resources/views/components/pdf/identitas-pasien.blade.php

    Blok identitas pasien STANDAR untuk semua cetakan PDF — MURNI IDENTITAS.
    Urutan & label baku (5 field):
      No. Rekam Medis · Nama Pasien · Jenis Kelamin · Tempat, Tgl. Lahir · Alamat

    Pakai:
      <x-pdf.identitas-pasien
          :rm="..." :nama="..." :jenisKelamin="..."
          :tempatLahir="..." :tglLahir="..." :umur="..." :alamat="..." />

    Field kosong tampil '-'.
    TANGGAL bukan bagian blok identitas — pakai label SPESIFIK sesuai dokumen
    (Tgl Pemeriksaan / Tgl Masuk / Tgl Keluar / Tgl Dikirim / Tgl Diterima, dll.)
    sebagai field konteks. Field konteks dioper lewat default slot sebagai
    <tr>...</tr> dan muncul DI BAWAH blok standar.

    UKURAN HURUF: default 'text-[11px]'. Dokumen yang perlu lebih besar (mis.
    kwitansi A4) mengoper :textClass="'text-[14px]'". WAJIB kelas STATIS yang
    sudah ada di CSS hasil build — kelas arbitrary yang dirakit dinamis tidak
    ikut ter-compile Tailwind sehingga ukurannya hilang saat dicetak.
    Baris yang dioper lewat slot mengatur ukurannya sendiri.
--}}
@props([
    'rm' => null,
    'nama' => null,
    'jenisKelamin' => null,
    'tempatLahir' => null,
    'tglLahir' => null,
    'umur' => null,
    'alamat' => null,
    'textClass' => 'text-[11px]',
])
@php
    $dash = fn($v) => filled($v) ? $v : '-';

    // Gabungan "Tempat, Tgl. Lahir (umur)" — toleran bila ada bagian yang kosong.
    $ttl = trim((filled($tempatLahir) ? $tempatLahir : '') . (filled($tempatLahir) && filled($tglLahir) ? ', ' : '') . (filled($tglLahir) ? $tglLahir : ''));
    if (filled($umur)) {
        $ttl = trim($ttl . ' (' . $umur . ')');
    }
    if ($ttl === '') {
        $ttl = '-';
    }
@endphp
<table cellpadding="0" cellspacing="0" {{ $attributes }}>
    <tr>
        <td class="py-0.5 {{ $textClass }} text-gray-500 whitespace-nowrap">No. Rekam Medis</td>
        <td class="py-0.5 {{ $textClass }} px-1">:</td>
        <td class="py-0.5 {{ $textClass }} font-bold">{{ $dash($rm) }}</td>
    </tr>
    <tr>
        <td class="py-0.5 {{ $textClass }} text-gray-500 whitespace-nowrap">Nama Pasien</td>
        <td class="py-0.5 {{ $textClass }} px-1">:</td>
        <td class="py-0.5 {{ $textClass }} font-bold">{{ filled($nama) ? strtoupper($nama) : '-' }}</td>
    </tr>
    <tr>
        <td class="py-0.5 {{ $textClass }} text-gray-500 whitespace-nowrap">Jenis Kelamin</td>
        <td class="py-0.5 {{ $textClass }} px-1">:</td>
        <td class="py-0.5 {{ $textClass }}">{{ $dash($jenisKelamin) }}</td>
    </tr>
    <tr>
        <td class="py-0.5 {{ $textClass }} text-gray-500 whitespace-nowrap">Tempat, Tgl. Lahir</td>
        <td class="py-0.5 {{ $textClass }} px-1">:</td>
        <td class="py-0.5 {{ $textClass }}">{{ $ttl }}</td>
    </tr>
    <tr>
        <td class="py-0.5 {{ $textClass }} text-gray-500 whitespace-nowrap align-top">Alamat</td>
        <td class="py-0.5 {{ $textClass }} px-1 align-top">:</td>
        <td class="py-0.5 {{ $textClass }}">{{ $dash($alamat) }}</td>
    </tr>
    {{ $slot }}
</table>
