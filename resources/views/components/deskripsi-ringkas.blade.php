@props([
    'baris' => 1,          // berapa baris ditampilkan saat diringkas
])

{{--
    Deskripsi modul yang panjang: dipotong sebaris, dengan tombol "Selengkapnya" untuk
    membuka penuh. Dipakai di kartu modul dokumen (tab) dan baris judul modal.

    Kenapa tombol, bukan sekadar truncate: judul + badge + deskripsi dijejer satu baris
    supaya kartunya ringkas, tapi sebagian deskripsi memuat keterangan yang benar-benar
    perlu dibaca (mis. nomor RM formulir, syarat TTD). Tanpa jalan membuka, keterangan itu
    hilang untuk selamanya dari layar.

    Tombolnya x-on:click.stop supaya tidak ikut memicu aksi baris/kartu di belakangnya.
--}}
<span {{ $attributes->merge(['class' => 'flex items-baseline flex-1 gap-1.5 min-w-0']) }}
    x-data="{ buka: false }">
    <span class="text-sm text-muted dark:text-gray-400" :class="buka ? '' : 'truncate'">{{ $slot }}</span>
    <button type="button" x-on:click.stop="buka = !buka"
        class="text-xs font-medium shrink-0 text-brand-green hover:underline dark:text-brand-lime"
        x-text="buka ? 'Ringkas' : 'Selengkapnya'"></button>
</span>
