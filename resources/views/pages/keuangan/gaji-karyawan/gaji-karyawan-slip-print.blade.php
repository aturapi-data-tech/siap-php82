{{-- Slip gaji karyawan — DomPDF A5 tegak, satu slip per halaman (cetak satuan & massal memakai berkas ini).
     Data: $slipList (array of ['header' => …, 'detail' => …] dari GajiKaryawan::slip()), $dicetak, $oleh.
     CSS inline (bukan kelas Tailwind) — DomPDF tidak membaca build Vite. --}}
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <title>Slip Gaji Karyawan</title>
    <style>
        @page { size: A5 portrait; margin: 18px 22px 26px 22px; }
        body { font-family: sans-serif; font-size: 10px; color: #111; }
        h1 { font-size: 13px; text-align: center; margin: 6px 0 2px; letter-spacing: 1px; }
        .sub { text-align: center; font-size: 10px; margin-bottom: 8px; }
        table { width: 100%; border-collapse: collapse; }
        .info td { padding: 1px 0; vertical-align: top; }
        .rinci td { padding: 3px 2px; border-bottom: 1px solid #e5e7eb; }
        .rinci .grup td { font-weight: bold; background: #f3f4f6; border-bottom: 1px solid #9ca3af; }
        .rinci .total td { font-weight: bold; border-top: 1px solid #111; border-bottom: none; }
        .rinci .terima td { font-weight: bold; font-size: 12px; border-top: 2px solid #111; border-bottom: 2px solid #111; padding: 5px 2px; }
        .angka { text-align: right; white-space: nowrap; font-family: monospace; }
        .ket { color: #6b7280; font-size: 9px; }
        .minus { color: #b91c1c; }
        .ttd td { text-align: center; vertical-align: top; padding-top: 10px; }
        .kaki { position: fixed; bottom: -14px; left: 0; right: 0; font-size: 8px; color: #6b7280; }
        .putus { page-break-after: always; }
    </style>
</head>

<body>
    <div class="kaki">Dicetak: {{ \App\Support\Format::waktu($dicetak) }}{{ $oleh ? ' oleh ' . $oleh : '' }} · Rahasia — hanya untuk karyawan yang bersangkutan</div>

    @foreach ($slipList as $slip)
        @php
            $header = $slip['header'];
            $detail = $slip['detail'];
            $bulanSlip = \Carbon\Carbon::createFromFormat('!Y-m', $header->periode);
            $namaBulan = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'][$bulanSlip->month];
        @endphp
        <div class="{{ $loop->last ? '' : 'putus' }}">
            <x-logo.identitas :showGaris="true" />

            <h1>SLIP GAJI KARYAWAN</h1>
            <div class="sub">Bulan kerja {{ $namaBulan }} {{ $bulanSlip->year }}{{ $header->gaji_status === \App\Support\GajiKaryawan::STATUS_DRAFT ? ' — DRAFT' : '' }}</div>

            <table class="info">
                <tr><td style="width:28%">Nama</td><td style="width:2%">:</td><td><b>{{ $header->emp_name }}</b></td></tr>
                <tr><td>ID Karyawan</td><td>:</td><td>{{ $header->emp_id }}</td></tr>
                <tr><td>Unit</td><td>:</td><td>{{ $header->unit_name ?? '—' }}</td></tr>
                <tr><td>Hari kerja</td><td>:</td><td>{{ (int) $header->hari_hadir }} hari</td></tr>
                @if ($header->no_rekening)
                    <tr><td>Rekening</td><td>:</td><td>{{ trim(($header->nama_bank ?? '') . ' ' . $header->no_rekening) }}</td></tr>
                @endif
            </table>

            <table class="rinci" style="margin-top:8px">
                @foreach (['D' => 'A. Pendapatan', 'P' => 'B. Potongan'] as $jenis => $namaKelompok)
                    <tr class="grup"><td colspan="2">{{ $namaKelompok }}</td></tr>
                    @forelse ($detail->where('jenis', $jenis) as $row)
                        <tr>
                            <td>
                                {{ \App\Support\GajiKaryawan::label($row->jenis, $row->kode) }}
                                @if ($row->keterangan)
                                    <span class="ket">({{ $row->keterangan }}{{ (float) $row->tarif > 0 ? ' × ' . \App\Support\GajiKaryawan::rupiah($row->tarif) : '' }})</span>
                                @endif
                            </td>
                            <td class="angka {{ (float) $row->nilai < 0 ? 'minus' : '' }}">{{ \App\Support\GajiKaryawan::rupiah($row->nilai) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="2" class="ket">— tidak ada —</td></tr>
                    @endforelse
                    <tr class="total">
                        <td>Total {{ $jenis === 'P' ? 'potongan' : 'pendapatan' }}</td>
                        <td class="angka {{ $jenis === 'P' ? 'minus' : '' }}">{{ \App\Support\GajiKaryawan::rupiah($jenis === 'P' ? $header->total_potongan : $header->total_pendapatan) }}</td>
                    </tr>
                @endforeach
                <tr class="terima">
                    <td>GAJI DITERIMA</td>
                    <td class="angka">Rp {{ \App\Support\GajiKaryawan::rupiah($header->gaji_diterima) }}</td>
                </tr>
            </table>
            <div class="ket" style="margin-top:4px; font-style:italic">
                Terbilang: {{ \App\Support\Terbilang::rupiah((int) round(max(0, (float) $header->gaji_diterima))) }}
            </div>

            {{-- Ruang tanda tangan: tinggi tetap lewat style + &nbsp; (pola TTD PDF — tanpa <br> berulang). --}}
            <table class="ttd">
                <tr>
                    <td style="width:50%">Bagian Keuangan<div style="height:38px">&nbsp;</div>(..............................)</td>
                    <td style="width:50%">Penerima<div style="height:38px">&nbsp;</div><b>{{ $header->emp_name }}</b></td>
                </tr>
            </table>
        </div>
    @endforeach
</body>

</html>
