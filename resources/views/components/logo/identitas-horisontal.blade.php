{{-- resources/views/components/logo/identitas-horisontal.blade.php --}}
{{-- Kop horisontal (gaya sirus-php82): logo persegi + nama & alamat instansi di kanannya.
     Pemakaian: <x-logo.identitas-horisontal /> atau <x-logo.identitas-horisontal :showGaris="false" />
     Isi dari menu Sistem › Identitas Instansi (App\Support\Identitas) — satu sumber dengan x-logo.identitas;
     prop tetap bisa ditimpa per pemakaian. Logo = "Logo Persegi" (ikon tanpa tulisan, karena nama
     instansi sudah ditulis di sebelahnya); tidak ada berkasnya → logo identitas. --}}
@props([
    'namaRs' => null,
    'alamat' => null,
    'telp' => null,
    'fax' => null,
    'website' => null,
    'showGaris' => true,
])
@php
    $identitas = \App\Support\Identitas::get();
    $namaRs ??= $identitas->nama;
    $alamat ??= \App\Support\Identitas::alamatLengkap();
    $telp ??= $identitas->telp;
    $fax ??= $identitas->fax;
    $website ??= $identitas->website;
    $logoPath = is_file(public_path('images/Logo Persegi.png')) ? public_path('images/Logo Persegi.png') : \App\Support\Identitas::logoPath();
    $kontak = collect([filled($telp) ? 'Telp. ' . $telp : null, filled($fax) ? 'Fax. ' . $fax : null])->filter()->implode("\u{2002}");
@endphp

<div class="w-full">
    <table class="w-full border-collapse">
        <tr>
            @if ($logoPath)
                <td class="align-middle pr-3 w-20">
                    <img src="data:image/png;base64,{{ base64_encode(file_get_contents($logoPath)) }}"
                        alt="Logo {{ $namaRs }}" class="h-20 w-auto block">
                </td>
            @endif
            <td class="align-middle text-left text-[9.5px] leading-snug text-gray-600">
                <div class="font-bold text-[13px] text-gray-900 mb-0.5">{{ $namaRs }}</div>
                @if (filled($alamat))
                    <div>{{ $alamat }}</div>
                @endif
                @if ($kontak !== '')
                    <div>{{ $kontak }}</div>
                @endif
                @if (filled($website))
                    <div>{{ $website }}</div>
                @endif
            </td>
        </tr>
    </table>

    @if ($showGaris)
        <div class="mt-3 border-t-[2.5px] border-green-700"></div>
        <div class="mt-0.5 border-t border-green-700"></div>
    @endif
</div>
