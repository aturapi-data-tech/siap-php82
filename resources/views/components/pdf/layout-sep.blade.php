{{-- resources/views/components/pdf/layout-sep.blade.php --}}
@props([
    'title' => 'SURAT ELEGIBILITAS PESERTA',
    'data' => [],
])
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
    <title>{{ $title }}</title>
    <style>
        @page {
            size: A5 landscape;
            margin: 4mm 6mm;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 0;
            font-size: 10px;
            font-family: sans-serif;
            color: #333;
        }

        .sep-wrapper {
            padding: 2mm 3mm;
        }

        {!! $pdfCss ? file_get_contents(public_path('build/' . $pdfCss)) : '' !!}
    </style>
</head>

<body>
    <div class="sep-wrapper">

        {{-- KOP: Logo BPJS + Judul + Nama RS (satu baris, gepeng) --}}
        <table class="w-full border-collapse" style="margin-bottom: 2px;">
            <tr>
                <td style="width: 120px; vertical-align: middle; padding: 0;">
                    @if (file_exists(public_path('images/bpjs-logo.png')))
                        <img src="data:image/png;base64,{{ base64_encode(file_get_contents(public_path('images/bpjs-logo.png'))) }}"
                            alt="BPJS Kesehatan" style="width: 115px; height: auto;">
                    @else
                        <div style="font-weight: bold; line-height: 1; white-space: nowrap;">
                            <span style="font-size: 11px; color: #0d6efd;">BPJS</span>
                            <span style="font-size: 9px; color: #28a745;">Kesehatan</span><br>
                            <span style="font-size: 5.5px; color: #888;">Badan Penyelenggara Jaminan Sosial</span>
                        </div>
                    @endif
                </td>
                <td style="vertical-align: middle; padding: 0 0 0 6px;">
                    <span style="font-size: 12px; font-weight: bold; letter-spacing: 0.3px;">{{ $title }}</span>
                    @if (!empty($data['namaRs']))
                        <br><span style="font-size: 11px; font-weight: bold;">{{ strtoupper($data['namaRs']) }}</span>
                    @endif
                </td>
            </tr>
        </table>

        {{-- Konten --}}
        {{ $slot }}

    </div>
</body>

</html>
