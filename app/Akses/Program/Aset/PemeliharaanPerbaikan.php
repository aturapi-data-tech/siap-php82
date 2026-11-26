<?php

use App\Support\HakAkses;

/*
 * Hak akses program "Pemeliharaan & Perbaikan Alat" — kelompok Aset (urutan & judul: _kelompok.php).
 * Format & pedoman: App\Support\ProgramAkses.
 */

return [
    'nama' => 'Pemeliharaan & Perbaikan Alat',
    'menu' => [
        ['group' => 'Aset', 'groupOrder' => 3, 'order' => 3, 'route' => 'aset.pemeliharaan-alat', 'title' => 'Pemeliharaan Alat', 'desc' => 'Entry & riwayat pemeriksaan alat harian / mingguan (B / P / R) + cetak checklist', 'roles' => ['admin', 'ipsrs', 'kepala ruang'], 'badge' => 'PM'],
        ['group' => 'Aset', 'groupOrder' => 3, 'order' => 4, 'route' => 'aset.perbaikan-alat', 'title' => 'Perbaikan Alat', 'desc' => 'Lapor kerusakan & proses perbaikan (teknisi / vendor) + laporan', 'roles' => ['admin', 'ipsrs', 'kepala ruang'], 'badge' => 'PBK'],
    ],
];
