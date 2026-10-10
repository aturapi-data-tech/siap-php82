{{-- resources/views/components/pdf/layout-kwitansi.blade.php --}}
@props([
    'title' => null,
    'data' => [],
    // Kode formulir RM (mis. "RM-02.01 · Rev.0") — lihat /panduan-dev/koding-formulir-rm.
    'kode' => null,
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
    <title>{{ $title ?? 'Kwitansi' }}</title>
    <style>
        @page {
            width: 105mm;
            height: 148.5mm;
            margin: 0;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 0;
            font-size: 9px;
            font-family: sans-serif;
        }

        .kwitansi-wrapper {
            padding: 6mm 8mm;
        }

        /* ── KODE FORMULIR RM (pojok kiri bawah) ── */
        .kode-formulir {
            position: fixed;
            bottom: 2mm;
            left: 8mm;
            font-size: 6px;
            color: #6b7280;
        }

        {!! $pdfCss ? file_get_contents(public_path('build/' . $pdfCss)) : '' !!}
    </style>
</head>

<body>
    {{-- KODE FORMULIR RM — pojok kiri bawah SETIAP halaman (fixed, di margin bawah halaman
         sehingga tak menimpa isi). Dicetak apa adanya, mis. "RM-02.01 · Rev.0". --}}
    @if (filled($kode))
        <div class="kode-formulir">{{ $kode }}</div>
    @endif
    <div class="kwitansi-wrapper">

        {{-- KOP: Identitas + Judul sejajar --}}
        <table class="w-full border-collapse">
            <tr>
                <td class="align-middle">
                    <x-logo.identitas-horisontal :showGaris="false" />
                </td>
                <td
                    class="align-middle text-right text-[14px] font-bold uppercase tracking-wide w-auto whitespace-nowrap text-gray-900">
                    {{ $title ?? 'Kwitansi Pembayaran' }}
                </td>
            </tr>
        </table>

        {{-- Garis --}}
        <div class="mt-0.5 border-t border-gray-400"></div>

        {{-- Konten utama (dari blade view) --}}
        {{ $slot }}

    </div>
</body>

</html>
