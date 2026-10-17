{{-- Badan slip gaji karyawan — satu potongan x-pdf.layout-a4-bagi-4 (dipakai gaji-karyawan-slip-print: satuan, massal,
     link WhatsApp). Mengikuti isi-slip gaji dokter sirus-php82: data karyawan, A. Pendapatan, B. Potongan,
     GAJI DITERIMA, terbilang, ruang tanda tangan. Kop & kerangka diurus layout; kelas slip-* didefinisikan di slip-print.

     Butuh: $slip (GajiKaryawan::slip(): ['header' => …, 'detail' => koleksi urut]). --}}

@php
    $header = $slip['header'];
    $detail = $slip['detail'];
    $rupiah = fn($nilai) => \App\Support\GajiKaryawan::rupiah($nilai);
    $bulanSlip = \Carbon\Carbon::createFromFormat('!Y-m', $header->periode);
    $namaBulan = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'][$bulanSlip->month];
    $kelompok = ['D' => 'A. Pendapatan', 'P' => 'B. Potongan'];
    $kota = \App\Support\Identitas::get()->kota;
@endphp

<table class="slip-data">
    <tr>
        <td class="label">Nama</td>
        <td class="titik">:</td>
        <td><b>{{ $header->emp_name }}</b></td>
    </tr>
    <tr>
        <td class="label">ID Karyawan</td>
        <td class="titik">:</td>
        <td>{{ $header->emp_id }}{{ $header->unit_name ? ' · ' . $header->unit_name : '' }}</td>
    </tr>
    <tr>
        <td class="label">Bulan Kerja</td>
        <td class="titik">:</td>
        <td>
            {{ $namaBulan }} {{ $bulanSlip->year }} · {{ (int) $header->hari_hadir }} hari kerja
            @if ($header->gaji_status === \App\Support\GajiKaryawan::STATUS_DRAFT)
                <span class="slip-draft">(DRAFT)</span>
            @endif
        </td>
    </tr>
    @if ($header->no_rekening)
        <tr>
            <td class="label">Rekening</td>
            <td class="titik">:</td>
            <td>{{ trim(($header->nama_bank ?? '') . ' ' . $header->no_rekening) }}</td>
        </tr>
    @endif
</table>

<table class="slip-rincian">
    <thead>
        <tr>
            <th style="text-align: left;">Uraian</th>
            <th class="jumlah">Jumlah (Rp)</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($kelompok as $jenis => $namaKelompok)
            <tr class="kelompok">
                <td colspan="2">{{ $namaKelompok }}</td>
            </tr>
            @forelse ($detail->where('jenis', $jenis) as $row)
                <tr>
                    <td>
                        {{ \App\Support\GajiKaryawan::label($row->jenis, $row->kode) }}
                        @if ($row->keterangan)
                            <span class="slip-ket">({{ $row->keterangan }}{{ (float) $row->tarif > 0 ? ' × ' . $rupiah($row->tarif) : '' }})</span>
                        @endif
                    </td>
                    <td class="jumlah">{{ $rupiah($row->nilai) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="2" class="slip-ket">&mdash; tidak ada &mdash;</td>
                </tr>
            @endforelse
            <tr class="total">
                <td>TOTAL {{ $jenis === 'P' ? 'POTONGAN' : 'PENDAPATAN' }}</td>
                <td class="jumlah">{{ $rupiah($jenis === 'P' ? $header->total_potongan : $header->total_pendapatan) }}</td>
            </tr>
        @endforeach

        <tr class="diterima">
            <td>GAJI DITERIMA</td>
            <td class="jumlah">{{ $rupiah($header->gaji_diterima) }}</td>
        </tr>
    </tbody>
</table>

{{-- Terbilang::rupiah() menerima int — pecahan rupiah dibulatkan lebih dulu. --}}
<div class="slip-terbilang">
    Terbilang: {{ \App\Support\Terbilang::rupiah((int) round(max(0, (float) $header->gaji_diterima))) }}
</div>

{{-- Ruang tanda tangan: tinggi tetap lewat CSS + &nbsp;, JANGAN <br> berulang — dompdf kerap
     mengabaikannya sehingga tanda tangan menumpuk (pola TTD PDF). --}}
<table class="slip-ttd">
    <tr>
        <td>
            Mengetahui,<br>Bagian Keuangan
            <div class="slip-ttd-ruang">&nbsp;</div>
            <div class="slip-ttd-nama">(&emsp;&emsp;&emsp;&emsp;)</div>
        </td>
        <td>
            {{ $kota ? $kota . ', ' : '' }}{{ \App\Support\Format::tanggal(now()) }}<br>Penerima
            <div class="slip-ttd-ruang">&nbsp;</div>
            <div class="slip-ttd-nama"><b>{{ $header->emp_name }}</b></div>
        </td>
    </tr>
</table>

<div class="slip-rahasia">Slip ini rahasia — hanya untuk karyawan yang bersangkutan.</div>
