{{-- resources/views/components/toggle-alpine.blade.php --}}
{{--
    Toggle ber-state ALPINE (klik tidak memanggil server) — tampilan sama dengan <x-toggle>.

    Kapan dipakai:
      Banyak toggle sekaligus yang dikumpulkan di browser lalu dikirim SEKALI (mis. matriks
      centang Role Control, ratusan toggle). <x-toggle> biasa memanggil server tiap klik
      ($set / wire:click) dan merender ulang komponen — terlalu berat untuk kasus begini.
      Untuk satu-dua toggle yang langsung tersimpan, tetap pakai <x-toggle>.

    Pemakaian (`class` & `title` ke <label> pembungkus; atribut lain diteruskan ke <input type="checkbox">):
      Kumpulan nilai (array Alpine):
        <x-toggle-alpine x-model="pilih" :value="$permissionId" label="Lihat" />
      Boolean Alpine:
        <x-toggle-alpine x-model="aktif" label="Aktif" />
      Tanpa label teks (mis. sel tabel) — beri aria-label:
        <x-toggle-alpine x-model="pilih" :value="$id" aria-label="Hapus Master Poli" />

    Catatan:
      - State di Alpine (x-model di checkbox tersembunyi); visual mengikuti `peer-checked`.
      - Untuk mengirim ke server, entangle array/boolean-nya di x-data induk
        (mis. `pilih: $wire.entangle('selectedPermissions')`, deferred) lalu simpan sekali.
      - Nilai checkbox DOM selalu string — simpan id sebagai string di sisi server.
--}}

@props([
    'label' => null,
    'disabled' => false,
])

<label {{ $attributes->only(['class', 'title'])->merge(['class' => 'inline-flex items-center gap-2 select-none ' . ($disabled ? 'cursor-not-allowed opacity-70' : 'cursor-pointer')]) }}>
    <input type="checkbox" class="sr-only peer" @disabled($disabled) {{ $attributes->except(['class', 'title']) }}>
    {{-- Track + bulatan (after:) — ukuran & warna sama dengan <x-toggle>; shrink-0 agar tak gepeng di sel sempit --}}
    <span
        class="relative h-6 transition rounded-full w-11 shrink-0 bg-gray-300 peer-checked:bg-brand
               peer-focus-visible:ring-2 peer-focus-visible:ring-brand/40
               after:absolute after:top-1 after:left-1 after:h-4 after:w-4 after:rounded-full after:bg-white
               after:shadow after:transition after:content-[''] peer-checked:after:translate-x-5"
        aria-hidden="true"></span>
    @if ($label !== null || trim($slot) !== '')
        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ $label ?? $slot }}</span>
    @endif
</label>
