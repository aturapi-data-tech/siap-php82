<?php

use App\Support\HakAkses;

/*
 * Hak akses program "Shift & Lokasi Absen" — kelompok Master (urutan & judul: _kelompok.php).
 * Format & pedoman: App\Support\ProgramAkses.
 */

return [
    'nama' => 'Shift & Lokasi Absen',
    'menu' => [
        ['group' => 'Master', 'groupOrder' => 1, 'order' => 2, 'route' => 'master.shift', 'title' => 'Master Shift', 'desc' => 'Jam kerja shift untuk absensi', 'roles' => [...HakAkses::AWAL_MASTER, 'hrd'], 'badge' => 'SFT'],
        ['group' => 'Master', 'groupOrder' => 1, 'order' => 2.5, 'route' => 'master.lokasi-absen', 'title' => 'Lokasi Absen HP', 'desc' => 'Titik RS / klinik + radius untuk absen GPS & selfie', 'roles' => [...HakAkses::AWAL_MASTER, 'hrd'], 'badge' => 'LOK'],
    ],
];
