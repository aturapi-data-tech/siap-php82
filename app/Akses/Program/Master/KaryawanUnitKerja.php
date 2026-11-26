<?php

use App\Support\HakAkses;

/*
 * Hak akses program "Karyawan & Unit Kerja" — kelompok Master (urutan & judul: _kelompok.php).
 * Format & pedoman: App\Support\ProgramAkses.
 */

return [
    'nama' => 'Karyawan & Unit Kerja',
    'menu' => [
        ['group' => 'Master', 'groupOrder' => 1, 'order' => 1, 'route' => 'master.karyawan', 'title' => 'Master Karyawan', 'desc' => 'Data karyawan, penanda nakes & status aktif', 'roles' => [...HakAkses::AWAL_MASTER, 'hrd', 'manager keuangan'], 'badge' => 'KRY'],
        ['group' => 'Master', 'groupOrder' => 1, 'order' => 1.5, 'route' => 'master.unit-kerja', 'title' => 'Master Unit Kerja', 'desc' => 'Unit / ruang karyawan — jadwal & shift per unit', 'roles' => [...HakAkses::AWAL_MASTER, 'hrd'], 'badge' => 'UNT'],
    ],
    'aksiKhusus' => [
        'karyawan.kelola' => ['label' => 'Ubah data Master Karyawan (termasuk Wajib Absen & STR/SIP) — tanpa ini hanya melihat', 'roles' => ['admin', 'hrd']],
    ],
];
