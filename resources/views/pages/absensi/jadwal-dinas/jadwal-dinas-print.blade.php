{{-- Cetak "Jadwal Masuk Kerja Bulan …" (format lapangan) — DomPDF A4 melintang, table-based. --}}
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <title>Jadwal Masuk Kerja</title>
    <style>
        @page { size: A4 landscape; margin: 22px 22px 30px 22px; }
        body { font-family: sans-serif; font-size: 9px; color: #111; }
        h1 { font-size: 15px; text-align: center; margin: 6px 0 8px; }
        table.jd { width: 100%; border-collapse: collapse; }
        table.jd { table-layout: fixed; }
        table.jd th, table.jd td { border: 1px solid #333; text-align: center; padding: 3px 1px; }
        table.jd th { background: #ff00ff; color: #fff; font-size: 9px; }
        table.jd th.minggu, table.jd td.minggu { background: #ff0000; color: #fff; }
        table.jd th.nama { background: #fbcfe8; color: #111; text-align: left; padding-left: 4px; }
        table.jd td.nama { background: #ffedd5; text-align: left; padding-left: 4px; font-weight: bold; white-space: nowrap; }
        .kaki { position: fixed; bottom: -16px; left: 0; right: 0; font-size: 8px; color: #6b7280; }
        .ket td { font-size: 9px; padding: 2px 6px; }
    </style>
</head>

<body>
    @php
        $namaBulan = ['', 'JANUARI', 'FEBRUARI', 'MARET', 'APRIL', 'MEI', 'JUNI', 'JULI', 'AGUSTUS', 'SEPTEMBER', 'OKTOBER', 'NOVEMBER', 'DESEMBER'][$awal->month];
        $jmlHari = $awal->daysInMonth;
        $shifts = \App\Support\Jadwal::shiftBerkode();
    @endphp

    <div class="kaki">
        <table width="100%"><tr>
            <td>Jadwal Masuk Kerja {{ \App\Support\Format::bulan($awal) }}</td>
            <td style="text-align:right">Dicetak: {{ \App\Support\Format::waktu($dicetak) }}{{ $oleh ? ' oleh ' . $oleh : '' }}</td>
        </tr></table>
    </div>

    <x-logo.identitas :showGaris="true" />

    <h1>JADWAL MASUK KERJA BULAN {{ $namaBulan }} {{ $awal->year }}</h1>

    <table class="jd">
        <thead>
            <tr>
                {{-- Lebar persentase: DomPDF mengabaikan lebar px pada table-layout fixed --}}
                <th class="nama" style="width:21%">NAMA KARYAWAN</th>
                <th class="nama" style="width:3%; text-align:center">P/S</th>
                @for ($h = 1; $h <= $jmlHari; $h++)
                    <th class="{{ $awal->copy()->day($h)->isSunday() ? 'minggu' : '' }}" style="width:{{ round(76 / $jmlHari, 3) }}%">{{ $h }}</th>
                @endfor
            </tr>
        </thead>
        <tbody>
            @foreach ($karyawan as $k)
                <tr>
                    <td class="nama">{{ mb_strtoupper($k->emp_name) }}</td>
                    <td class="nama" style="text-align:center">{{ $k->shift_default_kode }}</td>
                    @for ($h = 1; $h <= $jmlHari; $h++)
                        @php
                            $sel = $jadwal[$k->emp_id][$h] ?? null;
                            $kode = $sel ? \App\Support\Jadwal::kode($sel) : '';
                            [$bg, $fg] = \App\Support\Jadwal::WARNA_PDF[$kode] ?? ['#fdba74', '#111'];
                            $minggu = $awal->copy()->day($h)->isSunday();
                        @endphp
                        @if ($kode !== '')
                            <td style="background: {{ $bg }}; color: {{ $fg }}; font-weight: bold;">{{ $kode }}</td>
                        @else
                            <td class="{{ $minggu ? 'minggu' : '' }}"></td>
                        @endif
                    @endfor
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="ket" style="margin-top:6px">
        <tr>
            <td><b>NOTE :</b></td>
            @foreach ($shifts as $kode => $sh)
                <td><b>{{ $kode }}</b> = {{ $sh->shift_name }} ({{ $sh->jam_masuk }}–{{ $sh->jam_pulang }})</td>
            @endforeach
            <td><b>I</b> = Izin</td>
            <td><b>C</b> = Cuti</td>
            <td>Kosong / merah = Libur</td>
        </tr>
    </table>
</body>

</html>
