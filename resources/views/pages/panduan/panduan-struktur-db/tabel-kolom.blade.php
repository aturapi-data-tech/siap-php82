{{-- Satu tabel di Panduan Struktur Database (lipat/buka). Butuh: $tabel, $daftarKolom (StrukturDatabase::kolom()[$tabel]), $warna. --}}
@php
    $jumlahFk = $daftarKolom->whereNotNull('fk')->count();
@endphp
<details class="border rounded-xl border-hairline dark:border-gray-700" id="tabel-{{ strtolower($tabel) }}">
    <summary class="flex flex-wrap items-center gap-2 px-3 py-2 cursor-pointer">
        <span class="font-mono font-semibold" style="color: {{ $warna }}">{{ strtolower($tabel) }}</span>
        <span class="text-xs text-muted">{{ $daftarKolom->count() }} kolom{{ $jumlahFk ? ' · ' . $jumlahFk . ' FK' : '' }}</span>
    </summary>
    <div class="px-3 pb-3 overflow-x-auto">
        <table class="text-sm ds-table">
            <thead><tr><th>Kolom</th><th>Tipe</th><th class="ds-c">Wajib</th><th>Kunci</th></tr></thead>
            <tbody>
                @foreach ($daftarKolom as $row)
                    <tr>
                        <td class="font-mono {{ $row->pk ? 'font-bold' : '' }} {{ $row->fk ? 'italic' : '' }}">{{ strtolower($row->column_name) }}</td>
                        <td class="font-mono text-xs text-muted">{{ strtolower($row->tipe) }}</td>
                        <td class="ds-c">{{ $row->nullable === 'N' ? '✓' : '' }}</td>
                        <td class="text-xs">
                            @if ($row->pk)
                                <x-badge variant="brand">PK</x-badge>
                            @endif
                            @if ($row->fk)
                                <a href="#tabel-{{ strtolower($row->fk) }}" class="hover:underline"><x-badge variant="info">FK → {{ strtolower($row->fk) }}</x-badge></a>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</details>
