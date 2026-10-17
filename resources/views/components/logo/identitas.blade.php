{{-- resources/views/components/logo/identitas.blade.php --}}
{{-- Kop cetakan (DomPDF) — model kop modul dokumen sirus-php82: logo persegi + nama + alamat rata kanan, garis hijau ganda.
     Pemakaian: <x-logo.identitas /> atau <x-logo.identitas :showGaris="false" />
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
    $identitas = \App\Support\Identitas::get();
    $namaRs ??= $identitas->nama;
    $alamat ??= \App\Support\Identitas::alamatLengkap();
    $telp ??= $identitas->telp;
    $fax ??= $identitas->fax;
    $website ??= $identitas->website;
    $email ??= $identitas->email;
    $logoPath = \App\Support\Identitas::logoKopPath();
    $kontak = collect([
        filled($telp) ? 'Telp. ' . $telp : null,
        filled($fax) ? 'Fax. ' . $fax : null,
    ])->filter()->implode('  ');
    $web = collect([$email, $website])->filter()->implode(' · ');
    // Alamat dua baris seperti sirus: patah sebelum ", Kabupaten …" / ", Kota …"
    $barisAlamat = preg_split('/,\s*(?=(Kabupaten|Kab\.|Kota)\s)/i', (string) $alamat, 2);
@endphp

<table style="width:100%; border-collapse:collapse; margin-bottom:0;">
    <tr>
        {{-- Kiri: kosong (area ornamen) --}}
        <td style="width:45%; vertical-align:top;"></td>

        {{-- Kanan: logo + identitas instansi (right-align) --}}
        <td style="width:55%; text-align:right; vertical-align:top;">
            @if ($logoPath)
                <img src="{{ $logoPath }}" alt="Logo {{ $namaRs }}"
                    style="height:80px; width:auto; display:inline-block; margin-bottom:6px;">
            @endif
            <div style="font-size:9.5px; line-height:1.55; color:#333;">
                <div style="font-weight:bold; font-size:11px; color:#111;">{{ $namaRs }}</div>
                @if (filled($alamat))
                    <div>{{ $barisAlamat[0] }}@if (isset($barisAlamat[1])),<br>{{ $barisAlamat[1] }}@endif</div>
                @endif
                @if ($kontak !== '')
                    <div>{!! str_replace('  ', '&nbsp;&nbsp;', e($kontak)) !!}</div>
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
