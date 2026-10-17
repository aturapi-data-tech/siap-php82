@props([
    // Judul modal. Tulis karakter asli ("&", "→"), BUKAN entity HTML — prop di-escape,
    // jadi "&amp;" akan tampil literal.
    'judul' => '',
    // Atribut d path SVG (heroicons outline 24x24) untuk kotak ikon hijau di kiri judul.
    'ikon' => '',
    // Jalur pelayanan: 'RJ' | 'UGD' | 'RI' → badge "Rawat Jalan" / "UGD" / "Rawat Inap". null = tanpa badge.
    'jalur' => null,
    // Jumlah entri tersimpan; badge "N tersimpan" tampil bila > 0. null = modul tanpa hitungan.
    'jumlah' => null,
    // true → badge "Read Only" (form terkunci).
    'readOnly' => false,
    // Method Livewire tombol tutup.
    'tutup' => 'closeModal',
    // Nama x-modal untuk modal di luar komponen Livewire (mis. panduan di layout): tombol tutup
    // mengirim event close-modal lewat Alpine, prop tutup diabaikan. null = pakai wire:click tutup.
    'namaModal' => null,
    // SIAP: ekspresi Alpine tombol tutup (mis. "tryClose()" di dalam x-dirty-modal-content — tanya dulu
    // bila ada perubahan belum disimpan). Diisi = prop tutup & namaModal diabaikan.
    'tutupAlpine' => null,
])

@php
    $labelJalur = ['RJ' => 'Rawat Jalan', 'UGD' => 'UGD', 'RI' => 'Rawat Inap'][$jalur] ?? null;
    $teksDeskripsi = trim(strip_tags((string) $slot));
    $atributTutup = new \Illuminate\View\ComponentAttributeBag(match (true) {
        filled($tutupAlpine) => ['x-on:click' => $tutupAlpine],
        filled($namaModal)   => ['x-on:click' => "\$dispatch('close-modal', { name: '{$namaModal}' })"],
        default              => ['wire:click' => $tutup],
    });
@endphp

{{-- Diport dari sirus-php82 (components/modul-dokumen/header) — standar modal SIAP.
     Header modal modul dokumen (docs/modul-dokumen-ri-pattern.md §2a di sirus):
     [ikon] Judul · deskripsi ················ [jalur] [N tersimpan] [slot badge] [Read Only] [✕]
     Slot bawaan = deskripsi (boleh HTML); lebih dari 90 karakter otomatis diringkas dengan
     tombol "Selengkapnya". Slot "badge" = badge khusus modul (mis. Mode Lihat/Edit, transit).
     Tombol tutup = anak terakhir baris flex dengan ml-auto (aturan §2a). --}}
<div {{ $attributes->merge(['class' => 'px-6 py-2.5 border-b shrink-0 bg-surface-soft border-hairline dark:bg-gray-900 dark:border-gray-700']) }}>
    <div class="flex items-center min-w-0 gap-3">
        <div class="flex items-center justify-center w-7 h-7 rounded-lg shrink-0 bg-brand-green/10 dark:bg-brand-lime/15">
            <svg class="w-4 h-4 text-brand-green dark:text-brand-lime" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="{{ $ikon }}" />
            </svg>
        </div>

        <div class="flex items-baseline flex-1 min-w-0 gap-2">
            <h2 class="text-sm font-semibold truncate shrink-0 text-ink dark:text-gray-100">{{ $judul }}</h2>
            @if ($teksDeskripsi !== '')
                @if (mb_strlen($teksDeskripsi) > 90)
                    <x-deskripsi-ringkas class="hidden text-xs sm:flex">{{ $slot }}</x-deskripsi-ringkas>
                @else
                    <p class="flex-1 hidden min-w-0 text-xs truncate text-muted sm:block dark:text-gray-400">{{ $slot }}</p>
                @endif
            @endif
        </div>

        <div class="flex items-center gap-1.5 shrink-0">
            @if ($labelJalur)
                <x-badge class="shrink-0 whitespace-nowrap" variant="brand">{{ $labelJalur }}</x-badge>
            @endif
            @if (($jumlah ?? 0) > 0)
                <x-badge class="shrink-0 whitespace-nowrap" variant="info">{{ $jumlah }} tersimpan</x-badge>
            @endif
            {{ $badge ?? '' }}
            @if ($readOnly)
                <x-badge class="shrink-0 whitespace-nowrap" variant="danger">Read Only</x-badge>
            @endif
        </div>

        <x-icon-button color="gray" type="button" :attributes="$atributTutup" class="ml-auto shrink-0">
            <span class="sr-only">Tutup</span>
            <svg class="w-4 h-4" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" />
            </svg>
        </x-icon-button>
    </div>
</div>
