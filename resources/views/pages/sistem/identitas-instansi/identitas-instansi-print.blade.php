{{-- Contoh cetak kop + ringkasan identitas instansi (DomPDF A4). --}}
{{-- Layout baku sirus: x-pdf.layout-a4-with-out-background (kop identitas instansi, kaki Dicetak). --}}
<x-pdf.layout-a4-with-out-background :title="'PROFIL INSTANSI'" orientasi="portrait"
    :kakiKiri="'Contoh kop cetakan SIAP'" :dicetak="true" :showGaris="true">
    <x-slot:gaya>
        body { font-family: sans-serif; font-size: 11px; color: #111; }
        table.isi { width: 100%; border-collapse: collapse; }
        table.isi td { padding: 5px 4px; vertical-align: top; border-bottom: 1px dotted #bbb; }
        table.isi td.lbl { width: 28%; color: #444; }
    </x-slot:gaya>

    <table class="isi">
        <tr><td class="lbl">Nama Instansi</td><td>{{ $identitas->nama }}</td></tr>
        @if ($identitas->nama_singkat)<tr><td class="lbl">Nama Singkat</td><td>{{ $identitas->nama_singkat }}</td></tr>@endif
        @if ($identitas->tagline)<tr><td class="lbl">Tagline</td><td>{{ $identitas->tagline }}</td></tr>@endif
        <tr><td class="lbl">Alamat</td><td>{{ \App\Support\Identitas::alamatLengkap() ?: '-' }}</td></tr>
        @if ($identitas->jam_operasional ?? null)<tr><td class="lbl">Jam Operasional</td><td>{{ $identitas->jam_operasional }}</td></tr>@endif
        <tr><td class="lbl">Telepon / Fax</td><td>{{ collect([$identitas->telp, $identitas->fax ? 'Fax ' . $identitas->fax : null])->filter()->implode(' · ') ?: '-' }}</td></tr>
        <tr><td class="lbl">Email / Website</td><td>{{ collect([$identitas->email, $identitas->website])->filter()->implode(' · ') ?: '-' }}</td></tr>
        @if ($identitas->pimpinan)<tr><td class="lbl">{{ $identitas->jabatan_pimpinan ?: 'Pimpinan' }}</td><td>{{ $identitas->pimpinan }}</td></tr>@endif
        @if ($identitas->deskripsi)<tr><td class="lbl">Profil Singkat</td><td>{!! nl2br(e($identitas->deskripsi)) !!}</td></tr>@endif
    </table>
</x-pdf.layout-a4-with-out-background>
