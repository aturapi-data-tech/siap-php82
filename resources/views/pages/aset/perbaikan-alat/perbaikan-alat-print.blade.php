{{-- Cetak "Laporan Perbaikan Alat Medis" (format formulir RS, bukti MFK 7 EP d) — DomPDF A4, table-based. --}}
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <title>Laporan Perbaikan {{ $pb->pb_no }}</title>
    <style>
        @page { size: A4; margin: 28px 40px 34px 40px; }
        body { font-family: sans-serif; font-size: 11px; color: #111; }
        h1 { font-size: 14px; text-align: center; margin: 10px 0 2px; }
        .no { text-align: center; font-size: 10px; margin-bottom: 12px; }
        table.isi { width: 100%; border-collapse: collapse; }
        table.isi td { padding: 5px 4px; vertical-align: top; }
        table.isi td.lbl { width: 30%; }
        table.isi td.tt { width: 2%; }
        .garis { border-bottom: 1px dotted #888; min-height: 14px; }
        .bagian { margin-top: 10px; font-weight: bold; font-size: 11px; border-bottom: 1px solid #333; padding-bottom: 2px; }
        .kaki { position: fixed; bottom: -18px; left: 0; right: 0; font-size: 8px; color: #6b7280; }
        .ttd td { text-align: center; font-size: 11px; }
    </style>
</head>

<body>
    @php $W = fn($v) => $v ? \App\Support\Format::waktu($v) : '-'; @endphp

    <div class="kaki">
        <table width="100%"><tr>
            <td>{{ $pb->pb_no }}</td>
            <td style="text-align:right">Dicetak: {{ \App\Support\Format::waktu($dicetak) }}{{ $oleh ? ' oleh ' . $oleh : '' }}</td>
        </tr></table>
    </div>

    <x-logo.identitas :showGaris="true" />

    <h1>LAPORAN PERBAIKAN ALAT MEDIS</h1>
    <div class="no">No. {{ $pb->pb_no }} · Status: {{ \App\Support\Perbaikan::STATUS[$pb->pb_status][0] }}</div>

    <table class="isi">
        <tr><td class="lbl">Nama Alat</td><td class="tt">:</td><td class="garis">{{ $pb->asset_name }}</td></tr>
        <tr><td class="lbl">Kode Alat</td><td class="tt">:</td><td class="garis">{{ $pb->asset_id }}</td></tr>
        <tr><td class="lbl">Merk / Type</td><td class="tt">:</td><td class="garis">{{ trim(($pb->merk ?? '') . ' ' . ($pb->tipe_model ?? '')) ?: '-' }}</td></tr>
        <tr><td class="lbl">No. Seri</td><td class="tt">:</td><td class="garis">{{ $pb->no_seri ?: '-' }}</td></tr>
        <tr><td class="lbl">Ruangan</td><td class="tt">:</td><td class="garis">{{ $pb->ruang_id }} — {{ $pb->ruang_name }}</td></tr>
        <tr><td class="lbl">Kerusakan</td><td class="tt">:</td><td class="garis">{{ $pb->kerusakan }}</td></tr>
        <tr><td class="lbl">Analisa Kerusakan</td><td class="tt">:</td><td class="garis">{{ $pb->analisa ?: '-' }}</td></tr>
        <tr><td class="lbl">Penggantian Suku Cadang</td><td class="tt">:</td><td class="garis">{{ $pb->suku_cadang ?: '-' }}</td></tr>
        <tr><td class="lbl">Dikerjakan oleh</td><td class="tt">:</td>
            <td class="garis">
                {{ $pb->pb_pelaksana ? \App\Support\Perbaikan::PELAKSANA[$pb->pb_pelaksana] : '-' }}{{ $pb->teknisi ? ' — ' . $pb->teknisi : '' }}
                @if ($pb->vendor) · Vendor: {{ $pb->vendor }}@endif
                @if ($pb->no_service_report) · Service Report: {{ $pb->no_service_report }}@endif
            </td></tr>
        <tr><td class="lbl">Biaya</td><td class="tt">:</td><td class="garis">{{ (float) $pb->biaya > 0 ? 'Rp ' . number_format((float) $pb->biaya, 0, ',', '.') : '-' }}</td></tr>
    </table>

    <div class="bagian">Waktu</div>
    <table class="isi">
        <tr><td class="lbl">Tgl. Permintaan</td><td class="tt">:</td><td class="garis">{{ $W($pb->tgl_permintaan) }} (pelapor: {{ $pb->pelapor ?: '-' }})</td></tr>
        <tr><td class="lbl">Respon Teknisi</td><td class="tt">:</td>
            <td class="garis">
                {{ $W($pb->tgl_respon) }}
                @php $m = \App\Support\Perbaikan::menitRespon($pb); @endphp
                @if ($m !== null) ({{ \App\Support\Absensi::labelMenit($m) ?: '0 mnt' }} setelah permintaan)@endif
            </td></tr>
        <tr><td class="lbl">Tgl. Selesai Dikerjakan</td><td class="tt">:</td><td class="garis">{{ $W($pb->tgl_selesai) }}</td></tr>
        <tr><td class="lbl">Tgl. Diserahkan Kembali</td><td class="tt">:</td><td class="garis">{{ $W($pb->tgl_diserahkan) }}</td></tr>
        @if ($pb->keterangan)
            <tr><td class="lbl">Keterangan</td><td class="tt">:</td><td class="garis">{{ $pb->keterangan }}</td></tr>
        @endif
    </table>

    <table width="100%" class="ttd" style="margin-top:36px">
        <tr>
            <td colspan="3" style="text-align:left; padding-left:20px">Mengetahui,</td>
        </tr>
        <tr>
            <td width="33%">User,</td>
            <td width="34%">Teknisi,</td>
            <td width="33%">Penerima,</td>
        </tr>
        <tr><td colspan="3" style="height:60px"></td></tr>
        <tr>
            <td>( {{ $pb->pelapor ?: '..............................' }} )</td>
            <td>( {{ $pb->teknisi ?: '..............................' }} )</td>
            <td>( {{ $pb->penerima ?: '..............................' }} )</td>
        </tr>
    </table>
</body>

</html>
