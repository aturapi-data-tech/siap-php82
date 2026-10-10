{{-- resources/views/components/logo/identitas.blade.php --}}
{{-- Kop cetakan (DomPDF). Pemakaian: <x-logo.identitas /> atau <x-logo.identitas :showGaris="false" />
     Isi dari menu Sistem › Identitas Instansi (App\Support\Identitas); prop tetap bisa ditimpa per pemakaian. --}}
@props([
    'namaRs' => null,
    'alamat' => null,
    'telp' => null,
    'fax' => null,
    'website' => null,
    'email' => null,
    'showGaris' => true,
])
@php
    $idt = \App\Support\Identitas::get();
    $namaRs ??= $idt->nama;
    $alamat ??= \App\Support\Identitas::alamatLengkap();
    $telp ??= $idt->telp;
    $fax ??= $idt->fax;
    $website ??= $idt->website;
    $email ??= $idt->email;
    $logoPath = \App\Support\Identitas::logoPath();
    $kontak = collect([
        filled($telp) ? 'Telp. ' . $telp : null,
        filled($fax) ? 'Fax. ' . $fax : null,
    ])->filter()->implode('  ');
    $web = collect([$email, $website])->filter()->implode(' · ');
@endphp

<table style="width:100%; border-collapse:collapse; margin-bottom:0;">
    <tr>
        {{-- Kiri: kosong (area ornamen) --}}
        <td style="width:25%; vertical-align:top;"></td>

        {{-- Kanan: logo + identitas instansi (right-align) --}}
        <td style="width:75%; text-align:right; vertical-align:top;">
            @if ($logoPath)
                <img src="{{ $logoPath }}" alt="Logo {{ $namaRs }}"
                    style="height:60px; width:auto; display:inline-block; margin-bottom:6px;">
            @endif
            <div style="font-size:9.5px; line-height:1.55; color:#333;">
                @unless ($logoPath)
                    {{-- Nama hanya bila tanpa logo — logo sudah memuat nama instansi --}}
                    <div style="font-weight:bold; font-size:11px; color:#111;">{{ $namaRs }}</div>
                @endunless
                @if (filled($alamat))
                    <div>{{ $alamat }}</div>
                @endif
                @if ($kontak !== '')
                    <div>{{ $kontak }}</div>
                @endif
                @if ($web !== '')
                    <div>{{ $web }}</div>
                @endif
            </div>
        </td>
    </tr>

    @if ($showGaris)
        <tr>
            <td colspan="2" style="padding-top:10px;">
                <div style="border-top:2.5px solid #157547;"></div>
                <div style="border-top:1px solid #157547; margin-top:2px;"></div>
            </td>
        </tr>
    @endif
</table>
