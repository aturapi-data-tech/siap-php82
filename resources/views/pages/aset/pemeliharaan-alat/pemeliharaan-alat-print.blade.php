{{-- Cetak "Checklist Perawatan Alat" (format RS, bukti MFK 7) — DomPDF A4 melintang, table-based. --}}
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <title>Checklist Perawatan Alat</title>
    <style>
        @page { size: A4 landscape; margin: 22px 24px 30px 24px; }
        body { font-family: sans-serif; font-size: 9px; color: #111; }
        h1 { font-size: 13px; text-align: center; margin: 6px 0 2px; }
        .info td { font-size: 10px; padding: 1px 4px; }
        table.ck { width: 100%; border-collapse: collapse; margin-top: 6px; }
        table.ck th, table.ck td { border: 1px solid #555; padding: 2px 2px; vertical-align: middle; }
        table.ck th { background: #e5e7eb; font-size: 8px; }
        .c { text-align: center; }
        /* simbol (√) tidak ada di font default DomPDF → DejaVu Sans */
        .sim { font-family: 'DejaVu Sans', sans-serif; }
        .mono { font-family: monospace; }
        .B { color: #047857; font-weight: bold; }
        .P { color: #b45309; font-weight: bold; background: #fef3c7; }
        .R { color: #b91c1c; font-weight: bold; background: #fee2e2; }
        .kaki { position: fixed; bottom: -16px; left: 0; right: 0; font-size: 8px; color: #6b7280; }
        .ttd td { font-size: 10px; }
    </style>
</head>

<body>
    @php
        $harian = $frekuensi === 'H';
        $namaBulan = ['', 'JANUARI', 'FEBRUARI', 'MARET', 'APRIL', 'MEI', 'JUNI', 'JULI', 'AGUSTUS', 'SEPTEMBER', 'OKTOBER', 'NOVEMBER', 'DESEMBER'][$awal->month];
    @endphp

    <div class="kaki">
        <table width="100%"><tr>
            <td>Checklist Perawatan Alat — {{ $ruang }} — {{ \App\Support\Format::bulan($awal) }}</td>
            <td style="text-align:right">Dicetak: {{ \App\Support\Format::waktu($dicetak) }}{{ $oleh ? ' oleh ' . $oleh : '' }}</td>
        </tr></table>
    </div>

    <x-logo.identitas :showGaris="true" />

    <h1>CHECKLIST PERAWATAN ALAT</h1>
    <table class="info" width="100%">
        <tr>
            <td>RUANG : <b>{{ $ruang }}</b></td>
            <td style="text-align:right">BULAN : <b>{{ $namaBulan }}</b> &nbsp; TAHUN : <b>{{ $awal->year }}</b> &nbsp; ({{ $harian ? 'harian' : 'mingguan' }})</td>
        </tr>
    </table>

    <table class="ck">
        <thead>
            <tr>
                <th rowspan="2" style="width:16px">No</th>
                <th rowspan="2">Alat</th>
                <th rowspan="2" style="width:20px">Ada</th>
                <th rowspan="2" style="width:24px">Tidak Ada</th>
                <th colspan="{{ $slot }}">{{ $harian ? 'Tanggal' : 'Minggu' }}</th>
                <th rowspan="2" style="width:{{ $harian ? '90' : '160' }}px">Keterangan</th>
            </tr>
            <tr>
                @for ($s = 1; $s <= $slot; $s++)
                    <th class="c">{{ $s }}</th>
                @endfor
            </tr>
        </thead>
        <tbody>
            @php $ruangSebelum = null; @endphp
            @forelse ($checklist['alat'] as $i => $a)
                @if (($semua ?? false) && $a->ruang_id !== $ruangSebelum)
                    @php $ruangSebelum = $a->ruang_id; @endphp
                    <tr><td colspan="{{ $slot + 5 }}" style="background:#eef5f0; font-weight:bold;">{{ $a->ruang_id }} — {{ $a->ruang_name }}</td></tr>
                @endif
                @php
                    $catatan = $checklist['catatan'][$a->asset_id] ?? [];
                    $ket = collect($catatan)->pluck('keterangan')->filter()->unique()->implode('; ');
                @endphp
                <tr>
                    <td class="c">{{ $i + 1 }}</td>
                    <td>{{ $a->asset_name }}{{ (int) $a->jumlah > 1 ? ' / ' . (int) $a->jumlah : '' }}<br><span class="mono" style="font-size:7px;color:#555">{{ $a->asset_id }}</span></td>
                    <td class="c sim">&#8730;</td>
                    <td class="c"></td>
                    @for ($s = 1; $s <= $slot; $s++)
                        @php $h = $catatan[$s]->pm_hasil ?? ''; @endphp
                        <td class="c {{ $h }}">{{ $h }}</td>
                    @endfor
                    <td>{{ $ket }}</td>
                </tr>
            @empty
                <tr><td colspan="{{ $slot + 5 }}" class="c">Tidak ada alat.</td></tr>
            @endforelse
        </tbody>
    </table>

    <p style="font-size:9px; margin:4px 0 0">B : Baik &nbsp;&nbsp;·&nbsp;&nbsp; P : Perlu Perbaikan &nbsp;&nbsp;·&nbsp;&nbsp; R : Rusak</p>

    <table width="100%" class="ttd" style="margin-top:14px">
        <tr>
            <td width="50%" class="c">Petugas pemeriksa,<br><br><br><br>( ........................................ )</td>
            <td width="50%" class="c">Kepala Ruangan,<br><br><br><br>( ........................................ )</td>
        </tr>
    </table>
</body>

</html>
