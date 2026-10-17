{{-- Template print: Slip Gaji Karyawan — satuan & massal, 4 slip per lembar A4 (x-pdf.layout-a4-bagi-4).
     $potongan = true (link WhatsApp) → satu slip per halaman seukuran satu potongan, enak dibuka di HP.
     Badan slip di partial gaji-karyawan-slip-isi supaya cetak satuan, massal, dan link WhatsApp selalu identik.

     Butuh: $slipList (array ['header' => …, 'detail' => …] dari GajiKaryawan::slip()), $potongan. --}}
<x-pdf.layout-a4-bagi-4 title="Slip Gaji" :items="$slipList" isi="pages.keuangan.gaji-karyawan.gaji-karyawan-slip-isi"
    as="slip" :potongan="$potongan ?? false">
    <x-slot:gaya>
        table.slip-data { width: 100%; border-collapse: collapse; margin-top: 5px; font-size: 7.5px; }
        table.slip-data td { padding: 0.6px 0; vertical-align: top; }
        table.slip-data td.label { width: 58px; color: #4b5563; }
        table.slip-data td.titik { width: 6px; color: #4b5563; }

        table.slip-rincian { width: 100%; border-collapse: collapse; margin-top: 5px; font-size: 7.5px; }
        table.slip-rincian th { padding: 2px 0; font-size: 6.5px; text-transform: uppercase; letter-spacing: 0.05em; color: #4b5563; border-bottom: 0.8px solid #1f2937; }
        table.slip-rincian td { padding: 1.2px 0; vertical-align: top; }
        table.slip-rincian td.jumlah, table.slip-rincian th.jumlah { text-align: right; white-space: nowrap; width: 70px; }
        table.slip-rincian tr.kelompok td { padding-top: 3px; font-weight: bold; border-bottom: 0.5px solid #d1d5db; }
        table.slip-rincian tr.total td { font-weight: bold; border-top: 0.8px solid #1f2937; }
        table.slip-rincian tr.diterima td { padding: 3px 0; font-size: 9px; font-weight: bold; border-top: 1.5px solid #111; border-bottom: 1.5px solid #111; }
        .slip-ket { font-size: 6.5px; color: #6b7280; }
        .slip-draft { font-weight: bold; color: #b91c1c; }
        .slip-terbilang { margin-top: 3px; font-size: 6.5px; font-style: italic; color: #4b5563; }

        table.slip-ttd { width: 100%; margin-top: 6px; font-size: 7px; text-align: center; color: #374151; }
        table.slip-ttd td { width: 50%; vertical-align: top; }
        .slip-ttd-ruang { height: 26px; }
        .slip-ttd-nama { padding-top: 1px; border-top: 0.6px solid #1f2937; }
        .slip-rahasia { margin-top: 4px; font-size: 6px; color: #9ca3af; }
    </x-slot:gaya>
</x-pdf.layout-a4-bagi-4>
