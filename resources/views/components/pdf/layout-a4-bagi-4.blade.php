{{-- resources/views/components/pdf/layout-a4-bagi-4.blade.php
     Satu lembar A4 portrait dibagi 4 potongan (2 × 2, masing-masing ±105 × 148 mm) dengan garis potong putus-putus —
     untuk dokumen kecil yang dicetak banyak sekaligus (slip gaji, kartu, kwitansi kecil). Dokumen A4 penuh tetap
     memakai x-pdf.layout-a4-with-out-background.

     Tiap potongan: kop kecil (logo persegi + identitas instansi, judul di kanan, garis hijau ganda) lalu view $isi
     yang di-@include dengan satu item sebagai variabel $as.

     Pemakaian (dipanggil SEKALI — komponen memancarkan satu dokumen <html> utuh):
       <x-pdf.layout-a4-bagi-4 title="Slip Gaji" :items="$slipList" isi="pages.….slip-isi" as="slip">
           <x-slot:gaya> .slip-… { … } </x-slot:gaya>
       </x-pdf.layout-a4-bagi-4>
     potongan = true → satu item per halaman TANPA bingkai A4; pemanggil men-setPaper seukuran satu potongan
     (105 × 148 mm = [0, 0, 297.64, 419.53] pt), mis. untuk dibuka di HP.
     Ukuran teks/kelas isi sebaiknya CSS biasa di slot "gaya" — kelas Tailwind baru baru ada sesudah `npm run build`. --}}
@props([
    'title' => null,
    'items' => [],
    'isi',
    'as' => 'item',
    'potongan' => false,
])
@php
    $manifestPath = public_path('build/manifest.json');
    $pdfCss = null;
    if (file_exists($manifestPath)) {
        $manifest = json_decode(file_get_contents($manifestPath), true);
        $pdfCss = $manifest['resources/css/app.css']['file'] ?? null;
    }

    $perLembar = $potongan ? 1 : 4;
    $lembarList = collect($items)->values()->chunk($perLembar);

    $identitas = \App\Support\Identitas::get();
    $logoPath = \App\Support\Identitas::logoKopPath();
    $alamat = \App\Support\Identitas::alamatLengkap();
    $kontak = collect([
        filled($identitas->telp) ? 'Telp. ' . $identitas->telp : null,
        filled($identitas->fax) ? 'Fax. ' . $identitas->fax : null,
    ])->filter()->implode('  ');
@endphp
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <title>{{ $title ?? 'Dokumen' }}</title>
    <style>
        @page { margin: 0; }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            padding: 0;
            font-family: sans-serif;
            font-size: 8px;
            color: #111;
        }

        {!! $pdfCss ? file_get_contents(public_path('build/' . $pdfCss)) : '' !!}

        /* ── Lembar & potongan ── dompdf: tinggi sel TIDAK termasuk padding → 148mm − 2 × 6mm = 136mm
           (148, bukan 148,5, supaya pembulatan tidak mendorong baris kedua ke halaman baru) */
        table.lembar { border-collapse: collapse; table-layout: fixed; }
        table.lembar td.potongan { width: 105mm; height: 136mm; padding: 6mm 7mm; vertical-align: top; }
        table.lembar td.garis-kanan { border-right: 1px dashed #9ca3af; }
        table.lembar td.garis-bawah { border-bottom: 1px dashed #9ca3af; }
        .putus-halaman { page-break-after: always; }

        /* ── Kop kecil ── */
        table.kop-kecil { width: 100%; border-collapse: collapse; }
        table.kop-kecil td { vertical-align: middle; }
        table.kop-kecil td.logo { width: 34px; padding-right: 6px; }
        table.kop-kecil img { height: 34px; width: auto; display: block; }
        .kop-nama { font-size: 9px; font-weight: bold; color: #111; }
        .kop-alamat { font-size: 6.5px; line-height: 1.35; color: #4b5563; }
        .kop-judul { font-size: 9.5px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.04em; text-align: right; white-space: nowrap; }
        .kop-garis-tebal { border-top: 1.8px solid #157547; margin-top: 4px; }
        .kop-garis-tipis { border-top: 0.6px solid #157547; margin-top: 1.5px; }

        {{ $gaya ?? '' }}
    </style>
</head>

<body>
    @foreach ($lembarList as $lembar)
        @php $sel = $lembar->values()->pad($perLembar, null); @endphp
        <table class="lembar {{ $loop->last ? '' : 'putus-halaman' }}" style="width: {{ $potongan ? '105mm' : '210mm' }};">
            @foreach ($sel->chunk($potongan ? 1 : 2) as $baris)
                @php $barisAtas = $loop->first && !$potongan; @endphp
                <tr>
                    @foreach ($baris->values() as $kolom => $item)
                        <td class="potongan {{ !$potongan && $kolom === 0 ? 'garis-kanan' : '' }} {{ $barisAtas ? 'garis-bawah' : '' }}">
                            @if ($item !== null)
                                <table class="kop-kecil">
                                    <tr>
                                        @if ($logoPath)
                                            <td class="logo">
                                                <img src="data:image/png;base64,{{ base64_encode(file_get_contents($logoPath)) }}" alt="Logo {{ $identitas->nama }}">
                                            </td>
                                        @endif
                                        <td>
                                            <div class="kop-nama">{{ $identitas->nama }}</div>
                                            @if (filled($alamat))
                                                <div class="kop-alamat">{{ $alamat }}</div>
                                            @endif
                                            @if ($kontak !== '')
                                                <div class="kop-alamat">{!! str_replace('  ', '&nbsp;&nbsp;', e($kontak)) !!}</div>
                                            @endif
                                        </td>
                                        @if ($title)
                                            <td class="kop-judul">{{ $title }}</td>
                                        @endif
                                    </tr>
                                </table>
                                <div class="kop-garis-tebal"></div>
                                <div class="kop-garis-tipis"></div>

                                @include($isi, [$as => $item])
                            @endif
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </table>
    @endforeach
</body>

</html>
