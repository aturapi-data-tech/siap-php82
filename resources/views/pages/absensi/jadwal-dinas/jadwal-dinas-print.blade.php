{{-- Cetak "Jadwal Masuk Kerja Bulan …" (format lapangan) — DomPDF A4 melintang, table-based.
     Data: $awal, $karyawan, $jadwal, $shifts (kuas unit yang dicetak), $namaUnit (null = semua unit). --}}
{{-- Layout baku sirus: x-pdf.layout-a4-with-out-background (kop identitas instansi, kaki Dicetak). --}}
@php
    $namaBulan = ['', 'JANUARI', 'FEBRUARI', 'MARET', 'APRIL', 'MEI', 'JUNI', 'JULI', 'AGUSTUS', 'SEPTEMBER', 'OKTOBER', 'NOVEMBER', 'DESEMBER'][$awal->month];
    $jumlahHari = $awal->daysInMonth;
@endphp

<x-pdf.layout-a4-with-out-background :title="'JADWAL MASUK KERJA BULAN ' . ($namaBulan) . ' ' . ($awal->year) . ($namaUnit ? ' — ' . mb_strtoupper($namaUnit) : '')" orientasi="landscape"
    :kakiKiri="'Jadwal Masuk Kerja ' . (\App\Support\Format::bulan($awal)) . ($namaUnit ? ' · ' . $namaUnit : '')" :dicetak="true" :showGaris="true">
    <x-slot:gaya>
        body { font-family: sans-serif; font-size: 9px; color: #111; }
        table.jd { width: 100%; border-collapse: collapse; }
        table.jd { table-layout: fixed; }
        table.jd th, table.jd td { border: 1px solid #333; text-align: center; padding: 3px 1px; }
        table.jd th { background: #ff00ff; color: #fff; font-size: 9px; }
        table.jd th.minggu, table.jd td.minggu { background: #ff0000; color: #fff; }
        table.jd th.nama { background: #fbcfe8; color: #111; text-align: left; padding-left: 4px; }
        table.jd td.nama { background: #ffedd5; text-align: left; padding-left: 4px; font-weight: bold; white-space: nowrap; }
        .ket td { font-size: 9px; padding: 2px 6px; }
    </x-slot:gaya>

<table class="jd">
        <thead>
            <tr>
                {{-- Lebar persentase: DomPDF mengabaikan lebar px pada table-layout fixed --}}
                <th class="nama" style="width:21%">NAMA KARYAWAN</th>
                <th class="nama" style="width:3%; text-align:center">P/S</th>
                @for ($hari = 1; $hari <= $jumlahHari; $hari++)
                    <th class="{{ $awal->copy()->day($hari)->isSunday() ? 'minggu' : '' }}" style="width:{{ round(76 / $jumlahHari, 3) }}%">{{ $hari }}</th>
                @endfor
            </tr>
        </thead>
        <tbody>
            @foreach ($karyawan->groupBy(fn($row) => $row->unit_name ?? 'Belum ada unit') as $namaUnitKelompok => $karyawanUnit)
                @unless ($namaUnit)
                    <tr><td class="nama" colspan="{{ $jumlahHari + 2 }}" style="background:#eeeeee; font-weight:bold">{{ mb_strtoupper($namaUnitKelompok) }}</td></tr>
                @endunless
                @foreach ($karyawanUnit as $dataKaryawan)
                <tr>
                    <td class="nama">{{ mb_strtoupper($dataKaryawan->emp_name) }}</td>
                    <td class="nama" style="text-align:center">{{ $dataKaryawan->shift_default_kode }}</td>
                    @for ($hari = 1; $hari <= $jumlahHari; $hari++)
                        @php
                            $sel = $jadwal[$dataKaryawan->emp_id][$hari] ?? null;
                            $kode = $sel ? \App\Support\Jadwal::kode($sel) : '';
                            [$latar, $teks] = \App\Support\Jadwal::warna($kode, $sel->shift_warna ?? null);
                            $minggu = $awal->copy()->day($hari)->isSunday();
                        @endphp
                        @if ($kode !== '')
                            <td style="background: {{ $latar }}; color: {{ $teks }}; font-weight: bold;">{{ $kode }}</td>
                        @else
                            <td class="{{ $minggu ? 'minggu' : '' }}"></td>
                        @endif
                    @endfor
                </tr>
                @endforeach
            @endforeach
        </tbody>
    </table>

    <table class="ket" style="margin-top:6px">
        <tr>
            <td><b>NOTE :</b></td>
            @foreach ($shifts as $kode => $shift)
                <td><b>{{ $kode }}</b> = {{ $shift->shift_name }} ({{ $shift->jam_masuk }}–{{ $shift->jam_pulang }})</td>
            @endforeach
            <td><b>I</b> = Izin</td>
            <td><b>C</b> = Cuti</td>
            <td>Kosong / merah = Libur</td>
        </tr>
    </table>
</x-pdf.layout-a4-with-out-background>
