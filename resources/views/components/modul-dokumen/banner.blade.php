@props([
    // terkunci = form read-only (kuning) · lihat = melihat entri terkunci (biru) · lanjut = melanjutkan draft (hijau)
    'jenis' => 'terkunci',
])

@php
    $gaya = [
        'terkunci' => 'text-amber-700 bg-amber-50 border-amber-200 dark:bg-amber-900/20 dark:border-amber-600 dark:text-amber-300',
        'lihat' => 'text-sky-700 bg-sky-50 border-sky-200 dark:bg-sky-900/20 dark:border-sky-600 dark:text-sky-300',
        'lanjut' => 'text-brand-green bg-brand-lime/10 border-brand-lime/40 dark:text-brand-lime dark:bg-brand-lime/5',
    ][$jenis] ?? '';
    $ikon = [
        'terkunci' => ['M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z'],
        'lihat' => ['M15 12a3 3 0 11-6 0 3 3 0 016 0z', 'M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z'],
        'lanjut' => ['M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z'],
    ][$jenis] ?? [];
@endphp

{{-- Banner status di atas formulir modul dokumen. Kondisi tampil tetap di modul (@if $isFormLocked /
     $viewOnly / $editingKey); komponen ini hanya tampilan + teks baku. Slot bawaan = teks khusus
     (boleh HTML); kosong = teks baku per jenis. Margin tambahan lewat class. --}}
<div {{ $attributes->merge(['class' => "flex items-center gap-2 px-4 py-2.5 text-sm font-medium border rounded-xl {$gaya}"]) }}>
    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        @foreach ($ikon as $path)
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $path }}" />
        @endforeach
    </svg>
    <span>
        @if (trim(strip_tags((string) $slot)) !== '')
            {{ $slot }}
        @elseif ($jenis === 'terkunci')
            Data terkunci — tidak dapat diubah.
        @elseif ($jenis === 'lihat')
            Menampilkan entri terkunci (hanya lihat) — klik <strong>Selesai Melihat</strong> untuk kembali ke daftar.
        @else
            Sedang melanjutkan entri draft — <strong>Simpan Perubahan</strong> menyimpan ke entri ini, lalu kembali ke daftar.
        @endif
    </span>
</div>
