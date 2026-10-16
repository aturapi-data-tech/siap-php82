{{-- Badan slip gaji karyawan — dipakai gaji-karyawan-slip-print (satuan, massal, link WhatsApp).
     Mengikuti isi-slip gaji dokter sirus-php82: tabel uraian + jumlah, A. Pendapatan, B. Potongan,
     GAJI DITERIMA, terbilang, ruang tanda tangan. Kop & kerangka halaman diurus pemanggilnya.

     Butuh: $header (GajiKaryawan::slip()['header']), $detail (koleksi detail urut). --}}

@php
    $rupiah = fn($nilai) => \App\Support\GajiKaryawan::rupiah($nilai);
    $bulanSlip = \Carbon\Carbon::createFromFormat('!Y-m', $header->periode);
    $namaBulan = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'][$bulanSlip->month];
    $kelompok = ['D' => 'A. Pendapatan', 'P' => 'B. Potongan'];
@endphp

<table class="w-full mt-3 text-base">
    <tr>
        <td class="w-32 text-gray-600">Nama</td>
        <td class="w-2 text-gray-600">:</td>
        <td class="font-bold text-gray-900">{{ $header->emp_name }}</td>
    </tr>
    <tr>
        <td class="text-gray-600">ID Karyawan</td>
        <td class="text-gray-600">:</td>
        <td class="text-gray-900">{{ $header->emp_id }}{{ $header->unit_name ? ' · ' . $header->unit_name : '' }}</td>
    </tr>
    <tr>
        <td class="text-gray-600">Bulan Kerja</td>
        <td class="text-gray-600">:</td>
        <td class="text-gray-900">
            {{ $namaBulan }} {{ $bulanSlip->year }} · {{ (int) $header->hari_hadir }} hari kerja
            @if ($header->gaji_status === \App\Support\GajiKaryawan::STATUS_DRAFT)
                <span class="font-bold text-red-700">(DRAFT)</span>
            @endif
        </td>
    </tr>
    @if ($header->no_rekening)
        <tr>
            <td class="text-gray-600">Rekening</td>
            <td class="text-gray-600">:</td>
            <td class="text-gray-900">{{ trim(($header->nama_bank ?? '') . ' ' . $header->no_rekening) }}</td>
        </tr>
    @endif
</table>

<table class="w-full mt-3 text-base border-collapse">
    <thead>
        <tr class="border-b border-gray-800">
            <th class="py-1.5 text-left text-sm uppercase tracking-wide text-gray-600">Uraian</th>
            <th class="w-36 py-1.5 text-right text-sm uppercase tracking-wide text-gray-600">Jumlah (Rp)</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($kelompok as $jenis => $namaKelompok)
            <tr>
                <td colspan="2" class="pt-1.5 pb-0.5 font-bold text-gray-900 border-b border-gray-300">{{ $namaKelompok }}</td>
            </tr>
            @forelse ($detail->where('jenis', $jenis) as $row)
                <tr>
                    <td class="py-1 text-gray-800">
                        {{ \App\Support\GajiKaryawan::label($row->jenis, $row->kode) }}
                        @if ($row->keterangan)
                            <span class="text-sm text-gray-500">({{ $row->keterangan }}{{ (float) $row->tarif > 0 ? ' × ' . $rupiah($row->tarif) : '' }})</span>
                        @endif
                    </td>
                    <td class="py-1 text-right whitespace-nowrap text-gray-900">{{ $rupiah($row->nilai) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="2" class="py-1 text-sm text-gray-500">&mdash; tidak ada &mdash;</td>
                </tr>
            @endforelse
            <tr class="border-t border-gray-800">
                <td class="py-1 font-bold text-gray-900">TOTAL {{ $jenis === 'P' ? 'POTONGAN' : 'PENDAPATAN' }}</td>
                <td class="py-1 text-right font-bold whitespace-nowrap text-gray-900">
                    {{ $rupiah($jenis === 'P' ? $header->total_potongan : $header->total_pendapatan) }}
                </td>
            </tr>
        @endforeach

        <tr class="border-t-2 border-b-2 border-gray-900">
            <td class="py-2 text-lg font-bold text-gray-900">GAJI DITERIMA</td>
            <td class="py-2 text-right text-lg font-bold whitespace-nowrap text-gray-900">{{ $rupiah($header->gaji_diterima) }}</td>
        </tr>
    </tbody>
</table>

{{-- Terbilang::rupiah() menerima int — pecahan rupiah dibulatkan lebih dulu. --}}
<div class="mt-2 text-sm italic text-gray-600">
    Terbilang: {{ \App\Support\Terbilang::rupiah((int) round(max(0, (float) $header->gaji_diterima))) }}
</div>

{{-- Ruang tanda tangan: tinggi tetap lewat style + &nbsp;, JANGAN <br> berulang — dompdf kerap
     mengabaikannya sehingga tanda tangan menumpuk (pola TTD PDF). --}}
<table class="w-full mt-4 text-sm text-center text-gray-700">
    <tr>
        <td class="w-1/2 align-top">
            Mengetahui,<br>Bagian Keuangan
            <div style="height: 34px;">&nbsp;</div>
            <div class="pt-0.5 border-t border-gray-800">(&emsp;&emsp;&emsp;&emsp;)</div>
        </td>
        <td class="w-1/2 align-top">
            {{ \App\Support\Identitas::get()->kota ?: '' }}{{ \App\Support\Identitas::get()->kota ? ', ' : '' }}{{ \App\Support\Format::tanggal(now()) }}<br>Penerima
            <div style="height: 34px;">&nbsp;</div>
            <div class="pt-0.5 border-t border-gray-800 font-medium text-gray-900">{{ $header->emp_name }}</div>
        </td>
    </tr>
</table>

<div class="mt-3 text-xs text-gray-500">Slip ini rahasia — hanya untuk karyawan yang bersangkutan.</div>
