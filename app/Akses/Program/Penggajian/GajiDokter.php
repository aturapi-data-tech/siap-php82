<?php

use App\Support\HakAkses;

/*
 * Hak akses program "Gaji Dokter" — kelompok Penggajian (urutan & judul: _kelompok.php).
 * Aksi di dalamnya memakai gaji.kelola (program Gaji Karyawan).
 * Format & pedoman: App\Support\ProgramAkses.
 */

return [
    'nama' => 'Gaji Dokter',
    'menu' => [
        ['group' => 'Penggajian', 'groupOrder' => 4, 'order' => 5, 'route' => 'keuangan.gaji-dokter', 'title' => 'Gaji Dokter', 'desc' => 'Slip gaji dokter per bulan jasa dari transaksi SIRUS: struktur gaji, snapshot berversi, pemetaan dokter ke karyawan, lampiran pasien → terapkan ke slip gaji', 'roles' => HakAkses::AWAL_GAJI, 'badge' => 'GDK'],
    ],
];
