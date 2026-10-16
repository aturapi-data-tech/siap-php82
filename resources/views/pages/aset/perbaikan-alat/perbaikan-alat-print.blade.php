{{-- Cetak "Laporan Perbaikan Alat Medis" (format formulir RS, bukti MFK 7 EP d) — DomPDF A4, table-based. --}}
{{-- Layout baku sirus: x-pdf.layout-a4-with-out-background (kop identitas instansi, kaki Dicetak). --}}
@php $W = fn($v) => $v ? \App\Support\Format::waktu($v) : '-'; @endphp

<x-pdf.layout-a4-with-out-background :title="'LAPORAN PERBAIKAN ALAT MEDIS'" orientasi="portrait"
    :kakiKiri="($pb->pb_no)" :dicetak="true" :showGaris="true">
    <x-slot:gaya>
        body { font-family: sans-serif; font-size: 11px; color: #111; }
        .no { text-align: center; font-size: 10px; margin-bottom: 12px; }
        table.isi { width: 100%; border-collapse: collapse; }
        table.isi td { padding: 5px 4px; vertical-align: top; }
        table.isi td.lbl { width: 30%; }
        table.isi td.tt { width: 2%; }
        .garis { border-bottom: 1px dotted #888; min-height: 14px; }
        .bagian { margin-top: 10px; font-weight: bold; font-size: 11px; border-bottom: 1px solid #333; padding-bottom: 2px; }
        .ttd td { text-align: center; font-size: 11px; }
    </x-slot:gaya>

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
</x-pdf.layout-a4-with-out-background>
