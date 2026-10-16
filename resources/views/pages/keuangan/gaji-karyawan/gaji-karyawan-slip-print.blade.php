{{-- Template print: Slip Gaji Karyawan — satuan & massal (satu karyawan satu halaman).
     Mengikuti slip gaji dokter sirus-php82 (cetak-slip-gaji-massal-print): kerangka x-pdf.layout-kwitansi
     disalin ke sini karena komponen itu memancarkan satu dokumen <html> utuh — dipanggil berulang akan
     menghasilkan dokumen bersarang. Kop & judul sama dengan layout-kwitansi; badan slip di partial
     gaji-karyawan-slip-isi supaya cetak satuan, massal, dan link WhatsApp selalu identik.

     Butuh: $slipList (array ['header' => …, 'detail' => …] dari GajiKaryawan::slip()), $dicetak, $oleh. --}}

@php
    $manifestPath = public_path('build/manifest.json');
    $pdfCss = null;
    if (file_exists($manifestPath)) {
        $manifest = json_decode(file_get_contents($manifestPath), true);
        $pdfCss = $manifest['resources/css/app.css']['file'] ?? null;
    }
@endphp

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <title>Slip Gaji Karyawan</title>
    <style>
        @page {
            margin: 0;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            padding: 0;
            font-family: sans-serif;
        }

        /* Tiap slip satu halaman. page-break-after pada elemen TERAKHIR menyisakan halaman kosong,
           jadi pemutusnya kelas terpisah yang tidak dipakai slip terakhir. */
        .slip { padding: 8mm 10mm; }
        .putus-halaman { page-break-after: always; }

        {!! $pdfCss ? file_get_contents(public_path('build/' . $pdfCss)) : '' !!}
    </style>
</head>

<body class="text-sm text-gray-900">
    @foreach ($slipList as $slip)
        @php
            $header = $slip['header'];
            $detail = $slip['detail'];
        @endphp

        <div class="slip {{ $loop->last ? '' : 'putus-halaman' }}">
            {{-- Kop & judul: susunan yang sama dengan x-pdf.layout-kwitansi --}}
            <table class="w-full border-collapse">
                <tr>
                    <td class="align-middle">
                        <x-logo.identitas-horisontal :showGaris="false" />
                    </td>
                    <td class="align-middle text-right text-[14px] font-bold uppercase tracking-wide w-auto whitespace-nowrap text-gray-900">
                        Slip Gaji Karyawan
                    </td>
                </tr>
            </table>

            <div class="mt-0.5 border-t border-gray-400"></div>

            @include('pages.keuangan.gaji-karyawan.gaji-karyawan-slip-isi')
        </div>
    @endforeach
</body>

</html>
