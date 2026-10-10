@props([
    // Kode user penanda-tangan (myuser_code). Gambar diambil dari users.myuser_ttd_image
    // lewat App\Support\TtdUser (dua format kolom). Tidak render apa pun bila kode kosong,
    // user tak punya TTD, atau berkasnya hilang.
    'code' => '',
    // Alternatif: emp_id karyawan (users.emp_id) — dipakai petugas laboratorium yang
    // dicatat per emp_id, bukan myuser_code. Diabaikan bila 'code' terisi.
    'empId' => '',
    // Nama penanda-tangan — hanya untuk alt text.
    'name' => '',
    // Teks pengganti bila gambar TTD tak ada. Terisi → kotak berukuran SAMA tetap tampil
    // berisi teks ini (kolom TTD tetangga tetap sejajar); kosong → tak render apa pun.
    'teksKosong' => '',
])

@php
    $ttdImageUrl = $code !== '' ? \App\Support\TtdUser::urlDariKode($code) : \App\Support\TtdUser::urlDariEmpId($empId);
@endphp

{{-- Gambar TTD user untuk stempel petugas di layar (pasangan x-signature.ttd-petugas &
     blok stempel bespoke di inform consent / penolakan / penundaan / second opinion / dll).
     Kotak putih dibuat SAMA dengan hasil signature-pad pasien/saksi (x-signature.signature-result):
     lebar penuh + proporsi kanvas pad 460x180 (inline style — token aspect-[460/180] tidak ada
     di build Tailwind), sehingga tiga kolom TTD sejajar tingginya. --}}
@if ($ttdImageUrl || filled($teksKosong))
    <div {{ $attributes->merge(['class' => 'w-full overflow-hidden bg-white border border-gray-200 rounded-xl dark:border-gray-700']) }}>
        @if ($ttdImageUrl)
            <img src="{{ $ttdImageUrl }}" alt="Tanda tangan {{ $name }}" class="w-full object-contain p-2 mx-auto max-h-40" style="aspect-ratio: 460 / 180;" />
        @else
            <div class="flex items-center justify-center w-full p-2 mx-auto text-sm italic text-center max-h-40 text-muted-soft" style="aspect-ratio: 460 / 180;">
                {{ $teksKosong }}
            </div>
        @endif
    </div>
@endif
