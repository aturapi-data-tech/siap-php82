<?php

use App\Support\HakAkses;

/*
 * Hak akses program "Jadwal Dinas & Absen" — kelompok Absensi (urutan & judul: _kelompok.php).
 * Absen HP terbuka untuk semua (route publik, karyawan ketik ID sendiri) — tak ada centang.
 * Format & pedoman: App\Support\ProgramAkses.
 */

return [
    'nama' => 'Jadwal Dinas & Absen',
    'menu' => [
        ['group' => 'Absensi', 'groupOrder' => 2, 'order' => 0, 'route' => 'absensi.jadwal-dinas', 'title' => 'Jadwal Dinas', 'desc' => 'Jadwal masuk kerja bulanan (P / S / M / I / C) + cetak', 'roles' => ['admin', 'hrd', 'kepala ruang', 'supervisor unit'], 'badge' => 'JDW'],
        ['group' => 'Absensi', 'groupOrder' => 2, 'order' => 1, 'route' => 'absensi.kiosk', 'title' => 'Kiosk Absensi', 'desc' => 'Layar absen masuk/pulang — ketik/scan ID karyawan', 'roles' => ['admin', 'hrd', 'kiosk'], 'badge' => 'ABS'],
        ['group' => 'Absensi', 'groupOrder' => 2, 'order' => 1.5, 'route' => 'absensi.hp', 'title' => 'Absen HP', 'desc' => 'Absen masuk/pulang sendiri dari HP — GPS + selfie', 'roles' => [HakAkses::SEMUA_ROLE], 'badge' => 'HP'],
    ],
];
