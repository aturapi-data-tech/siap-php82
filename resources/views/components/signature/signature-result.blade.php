@props([
    'signature' => '',
    'date' => '',
    'label' => '',
    'disabled' => false,
    'wireMethod' => 'clearSignature',
])

{{-- Hasil TTD pasien/wali/saksi (gambar dari signature-pad).
     STANDAR TATA LETAK TTD DI LAYAR: kotak gambar SELALU baris pertama di bawah judul kolom,
     tanpa teks apa pun di atasnya — supaya kotak pasien, saksi, dan petugas
     (x-signature.ttd-gambar di dalam ttd-petugas) sejajar di satu baris grid.
     Kotak dibuat SAMA PERSIS dengan ttd-gambar: lebar penuh + proporsi kanvas pad 460x180.
     Waktu TTD (prop date) tampil DI BAWAH kotak dengan gaya baris "Waktu TTD" ttd-petugas.
     Prop signature boleh data-URL (warisan, inline) ATAU referensi "TTD:<no>" ke RSTXN_TTDS —
     App\Support\TtdPasien::sumberGambar() yang menyelesaikannya. --}}
<div>

    @if ($label)
        <p class="mb-1 text-xs font-medium text-center text-gray-600 dark:text-gray-400">{{ $label }}</p>
    @endif

    <div class="w-full overflow-hidden bg-white border border-gray-200 rounded-xl dark:border-gray-700">
        <img src="{{ \App\Support\TtdPasien::sumberGambar($signature) }}" alt="Tanda Tangan" class="w-full object-contain p-2 mx-auto max-h-40"
            style="aspect-ratio: 460 / 180;" />
    </div>

    @if ($date)
        <p class="mt-2 text-sm"><span class="text-muted">Waktu TTD:</span>
            <span class="font-semibold text-ink dark:text-gray-200">{{ $date }}</span></p>
    @endif

    @if (!$disabled)
        <div class="text-center">
            <x-secondary-button wire:click="{{ $wireMethod }}" type="button" class="mt-3 text-xs gap-1">
                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
                Hapus &amp; Ulangi TTD
            </x-secondary-button>
        </div>
    @endif

</div>
