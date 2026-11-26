<?php

use App\Support\HakAkses;

/*
 * Hak akses program "Ruang" — kelompok Master (urutan & judul: _kelompok.php).
 * Format & pedoman: App\Support\ProgramAkses.
 */

return [
    'nama' => 'Ruang',
    'menu' => [
        ['group' => 'Master', 'groupOrder' => 1, 'order' => 3, 'route' => 'master.ruang', 'title' => 'Master Ruang', 'desc' => 'Ruang / unit — lokasi alat & awalan kode alat', 'roles' => [...HakAkses::AWAL_MASTER, 'ipsrs'], 'badge' => 'RNG'],
    ],
];
