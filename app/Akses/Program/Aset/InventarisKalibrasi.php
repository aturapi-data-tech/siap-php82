<?php

use App\Support\HakAkses;

/*
 * Hak akses program "Inventaris & Kalibrasi Alat" — kelompok Aset (urutan & judul: _kelompok.php).
 * Format & pedoman: App\Support\ProgramAkses.
 */

return [
    'nama' => 'Inventaris & Kalibrasi Alat',
    'menu' => [
        ['group' => 'Aset', 'groupOrder' => 3, 'order' => 1, 'route' => 'master.alat', 'title' => 'Inventaris Alat', 'desc' => 'Alat medis & non medis per ruang + status kalibrasi', 'roles' => ['admin', 'ipsrs'], 'badge' => 'INV'],
        ['group' => 'Aset', 'groupOrder' => 3, 'order' => 2, 'route' => 'aset.jadwal-kalibrasi', 'title' => 'Jadwal Kalibrasi', 'desc' => 'Program kalibrasi tahunan alat (MFK 7) + cetak PDF', 'roles' => ['admin', 'ipsrs'], 'badge' => 'KAL'],
    ],
];
