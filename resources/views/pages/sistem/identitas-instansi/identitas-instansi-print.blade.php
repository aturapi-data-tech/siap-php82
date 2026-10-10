{{-- Contoh cetak kop + ringkasan identitas instansi (DomPDF A4). --}}
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <title>Contoh Kop — {{ $idt->nama }}</title>
    <style>
        @page { size: A4; margin: 28px 40px 34px 40px; }
        body { font-family: sans-serif; font-size: 11px; color: #111; }
        h1 { font-size: 14px; text-align: center; margin: 16px 0 10px; }
        table.isi { width: 100%; border-collapse: collapse; }
        table.isi td { padding: 5px 4px; vertical-align: top; border-bottom: 1px dotted #bbb; }
        table.isi td.lbl { width: 28%; color: #444; }
        .kaki { position: fixed; bottom: -18px; left: 0; right: 0; font-size: 8px; color: #6b7280; }
    </style>
</head>

<body>
    <div class="kaki">
        <table width="100%"><tr>
            <td>Contoh kop cetakan SIAP</td>
            <td style="text-align:right">Dicetak: {{ \App\Support\Format::waktu($dicetak) }}{{ $oleh ? ' oleh ' . $oleh : '' }}</td>
        </tr></table>
    </div>

    <x-logo.identitas :showGaris="true" />

    <h1>PROFIL INSTANSI</h1>

    <table class="isi">
        <tr><td class="lbl">Nama Instansi</td><td>{{ $idt->nama }}</td></tr>
        @if ($idt->nama_singkat)<tr><td class="lbl">Nama Singkat</td><td>{{ $idt->nama_singkat }}</td></tr>@endif
        @if ($idt->tagline)<tr><td class="lbl">Tagline</td><td>{{ $idt->tagline }}</td></tr>@endif
        <tr><td class="lbl">Alamat</td><td>{{ \App\Support\Identitas::alamatLengkap() ?: '-' }}</td></tr>
        @if ($idt->jam_operasional ?? null)<tr><td class="lbl">Jam Operasional</td><td>{{ $idt->jam_operasional }}</td></tr>@endif
        <tr><td class="lbl">Telepon / Fax</td><td>{{ collect([$idt->telp, $idt->fax ? 'Fax ' . $idt->fax : null])->filter()->implode(' · ') ?: '-' }}</td></tr>
        <tr><td class="lbl">Email / Website</td><td>{{ collect([$idt->email, $idt->website])->filter()->implode(' · ') ?: '-' }}</td></tr>
        @if ($idt->pimpinan)<tr><td class="lbl">{{ $idt->jabatan_pimpinan ?: 'Pimpinan' }}</td><td>{{ $idt->pimpinan }}</td></tr>@endif
        @if ($idt->deskripsi)<tr><td class="lbl">Profil Singkat</td><td>{!! nl2br(e($idt->deskripsi)) !!}</td></tr>@endif
    </table>
</body>

</html>
