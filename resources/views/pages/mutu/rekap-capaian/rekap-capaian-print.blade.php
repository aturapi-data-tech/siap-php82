{{-- Cetak Rekap Capaian Indikator Mutu (DomPDF, A4 melintang) — table-based, tanpa flex/grid. --}}
{{-- Run chart dimuat sebagai <img> data URI SVG (RunChart::dataUri) — DomPDF tidak merender <svg> inline. --}}
@php
    use App\Support\IndikatorMutu;
    $sel = fn($data) => $data['capaian'] === null ? '—' : ($data['tercapai'] ? '✓ ' : '✗ ') . IndikatorMutu::angka($data['capaian'], 1);
@endphp

<x-pdf.layout-a4-with-out-background :title="'REKAP CAPAIAN INDIKATOR MUTU TAHUN ' . $tahun" orientasi="landscape"
    :kakiKiri="'Capaian Indikator Mutu ' . $tahun . ' — ' . $cakupan" :dicetak="true" :showGaris="true">
    <x-slot:gaya>
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 7.5px; color: #111; }
        .sub { text-align: center; margin: 0 0 8px; font-size: 9px; }
        h3 { font-size: 9.5px; margin: 10px 0 4px; }
        table.rekap { width: 100%; border-collapse: collapse; page-break-inside: auto; }
        table.rekap tr { page-break-inside: avoid; }
        table.rekap th, table.rekap td { border: 1px solid #777; padding: 2px 2px; vertical-align: middle; }
        table.rekap th { background: #e3f0e6; font-size: 7.5px; }
        .c { text-align: center; white-space: nowrap; }
        .kecil { font-size: 6.5px; color: #555; }
        .ok { color: #23744a; font-weight: bold; }
        .gagal { color: #9c3232; font-weight: bold; }
        .tw { background: #f4f6f4; }
        table.grafik { width: 100%; border-collapse: collapse; }
        table.grafik td { width: 50%; vertical-align: top; padding: 4px 6px 10px; }
        .judul-grafik { font-size: 8px; font-weight: bold; margin-bottom: 2px; }
        .catatan { font-size: 7px; color: #444; margin-top: 6px; }
    </x-slot:gaya>

    <p class="sub">{{ $cakupan }} · Sumber: kertas kerja digital SIAP · Triwulan & tahun = jumlah pembilang ÷ jumlah penyebut</p>

    @foreach ($tabelPerKelompok as $kelompok => $tabel)
        <h3>{{ IndikatorMutu::KELOMPOK[$kelompok] }}</h3>
        <table class="rekap">
            <thead>
                <tr>
                    <th style="width:14px">No</th>
                    <th>Indikator</th>
                    <th style="width:34px">Target</th>
                    @foreach (IndikatorMutu::NAMA_BULAN as $namaBulan)
                        <th style="width:30px">{{ $namaBulan }}</th>
                    @endforeach
                    @foreach (['I', 'II', 'III', 'IV'] as $romawi)
                        <th class="tw" style="width:30px">TW {{ $romawi }}</th>
                    @endforeach
                    <th style="width:32px">{{ $tahun }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($tabel as $kode => $baris)
                    <tr>
                        <td class="c">{{ $loop->iteration }}</td>
                        <td>
                            {{ $baris['definisi']['judul'] }}
                            <div class="kecil">{{ $kode }}@if ($kelompok === 'IMP-RS' && $baris['definisi']['kategoriRs']) · {{ $baris['definisi']['kategoriRs'] }}@endif</div>
                        </td>
                        <td class="c">{{ IndikatorMutu::labelTarget($baris['definisi']) }}</td>
                        @foreach ([...array_values($baris['bulan']), ...array_values($baris['triwulan']), $baris['tahun']] as $indeks => $data)
                            <td class="c {{ $indeks >= 12 && $indeks < 16 ? 'tw' : '' }}">
                                <span class="{{ $data['capaian'] === null ? '' : ($data['tercapai'] ? 'ok' : 'gagal') }}">{{ $sel($data) }}</span>
                                @if ($data['capaian'] !== null)
                                    <div class="kecil">{{ IndikatorMutu::angka($data['pembilang'], 0) }}/{{ IndikatorMutu::angka($data['penyebut'], 0) }}</div>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endforeach

    <p class="catatan">✓ tercapai · ✗ tidak tercapai · — tidak ada kasus. Angka kecil = pembilang/penyebut.
        Indikator bertarget ≤ makin kecil makin baik.</p>

    @if ($grafikPdf !== [])
        <div style="page-break-before: always;"></div>
        <h3>Run Chart Capaian {{ $tahun }} — {{ $cakupan }}</h3>
        <table class="grafik">
            @foreach (array_chunk($grafikPdf, 2, true) as $pasangan)
                <tr>
                    @foreach ($pasangan as $kode => $grafik)
                        <td>
                            <div class="judul-grafik">{{ $kode }} — {{ $grafik['definisi']['judul'] }}</div>
                            <img src="{{ $grafik['gambar'] }}" style="width:100%; height:auto;" alt="Run chart {{ $kode }}">
                        </td>
                    @endforeach
                    @if (count($pasangan) === 1)
                        <td></td>
                    @endif
                </tr>
            @endforeach
        </table>
    @endif
</x-pdf.layout-a4-with-out-background>
