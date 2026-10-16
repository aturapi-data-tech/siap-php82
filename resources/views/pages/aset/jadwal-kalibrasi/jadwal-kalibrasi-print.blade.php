{{-- Cetak Jadwal Kalibrasi Alat (DomPDF, A4 melintang) — table-based, tanpa flex/grid. --}}
{{-- Layout baku sirus: x-pdf.layout-a4-with-out-background (kop identitas instansi, kaki Dicetak). --}}
<x-pdf.layout-a4-with-out-background :title="'JADWAL KALIBRASI ALAT KESEHATAN TAHUN ' . ($tahun)" orientasi="landscape"
    :kakiKiri="'Jadwal Kalibrasi Alat ' . ($tahun) . ' — ' . ($ruang)" :dicetak="true" :showGaris="true">
    <x-slot:gaya>
        body { font-family: sans-serif; font-size: 9.5px; color: #111; }
        .sub { text-align: center; margin: 0 0 10px; font-size: 10px; }
        table.jadwal { width: 100%; border-collapse: collapse; }
        table.jadwal th, table.jadwal td { border: 1px solid #555; padding: 3px 4px; vertical-align: middle; }
        table.jadwal th { background: #e5e7eb; font-size: 9px; }
        .c { text-align: center; }
        .mono { font-family: monospace; }
        .j-rencana { background: #e0f2fe; }
        .j-terlewat { background: #fee2e2; }
        .j-terpenuhi { background: #f3f4f6; color: #6b7280; }
        /* ✓ tidak ada di font default DomPDF → pakai DejaVu Sans (bawaan DomPDF) */
        .ok { color: #047857; font-weight: bold; font-family: 'DejaVu Sans', sans-serif; }
        .tl { color: #b91c1c; font-weight: bold; }
        .legenda td { padding: 2px 6px; font-size: 9px; }
        .ttd td { font-size: 10px; padding-top: 4px; }
    </x-slot:gaya>

    <p class="sub">Ruang: {{ $ruang }} · Acuan: Permenkes No. 54 Tahun 2015 (kalibrasi paling sedikit 1 kali dalam 1 tahun)</p>

    <table class="jadwal">
        <thead>
            <tr>
                <th style="width:18px">No</th>
                <th>Nama Alat</th>
                <th>Kode Alat</th>
                <th>Merk / Type</th>
                <th>No. Seri</th>
                <th>Ruang</th>
                @foreach (['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'] as $bln)
                    <th class="c" style="width:26px">{{ $bln }}</th>
                @endforeach
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($jadwal as $i => $r)
                @php $a = $r['alat']; @endphp
                <tr>
                    <td class="c">{{ $i + 1 }}</td>
                    <td>{{ $a->asset_name }}</td>
                    <td class="mono">{{ $a->asset_id }}</td>
                    <td>{{ $a->merk }} {{ $a->tipe_model }}</td>
                    <td class="mono">{{ $a->no_seri }}</td>
                    <td class="c">{{ $a->ruang_id }}</td>
                    @foreach ($r['bulan'] as $b)
                        <td class="c {{ $b['jadwal'] ? 'j-' . $b['jadwal'] : '' }}">
                            {{ $b['jadwal'] ? 'J' : '' }}@if ($b['kal'] === 'L')<span class="ok"> &#10003;</span>@elseif ($b['kal'] === 'T')<span class="tl"> X</span>@endif
                        </td>
                    @endforeach
                    <td>{{ $r['status'][1] ?? '' }}</td>
                </tr>
            @empty
                <tr><td colspan="19" class="c">Tidak ada alat wajib kalibrasi.</td></tr>
            @endforelse
        </tbody>
    </table>

    <table class="legenda" style="margin-top:6px">
        <tr>
            <td class="j-rencana">J</td><td>Jatuh tempo kalibrasi</td>
            <td class="j-terlewat">J</td><td>Jatuh tempo terlewat / belum pernah</td>
            <td class="j-terpenuhi">J</td><td>Sudah dikalibrasi ulang</td>
            <td class="ok">&#10003;</td><td>Dikalibrasi — Laik Pakai</td>
            <td class="tl">X</td><td>Dikalibrasi — Tidak Laik Pakai</td>
        </tr>
    </table>

    <table width="100%" class="ttd" style="margin-top:18px">
        <tr>
            <td width="65%"></td>
            <td class="c">
                {{ \App\Support\Identitas::get()->kota ?: config('identitas.kota') }}, {{ \App\Support\Format::tanggal($dicetak) }}<br>
                Penanggung Jawab Peralatan Medis<br><br><br><br>
                ( ................................................ )
            </td>
        </tr>
    </table>
</x-pdf.layout-a4-with-out-background>
