{{-- resources/views/components/pdf/layout-etiket.blade.php --}}
{{--
    Wrapper ukuran 6x4cm untuk berbagai jenis etiket.
    Konten bebas diisi via slot.

    Pemakaian:
    <x-pdf.layout-etiket>
        ...isi konten etiket...
    </x-pdf.layout-etiket>
--}}

<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    @php
        $manifest = json_decode(file_get_contents(public_path('build/manifest.json')), true);
        $pdfCss = $manifest['resources/css/app.css']['file'] ?? null;
    @endphp
    <style>
        @page {
            size: 6cm 4cm;
            margin: 2mm;
        }

        body {
            width: 56mm;
            height: 36mm;
            overflow: hidden;
        }

        {!! $pdfCss ? file_get_contents(public_path('build/' . $pdfCss)) : '' !!}
    </style>
</head>

<body class="font-sans text-[7px]">

    {{ $slot }}

</body>

</html>
