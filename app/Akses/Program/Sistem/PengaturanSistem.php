<?php

use App\Support\HakAkses;

/*
 * Hak akses program "Pengaturan Sistem" — kelompok Sistem (urutan & judul: _kelompok.php).
 * Format & pedoman: App\Support\ProgramAkses.
 */

return [
    'nama' => 'Pengaturan Sistem',
    'menu' => [
        ['group' => 'Sistem', 'groupOrder' => 15, 'order' => 0, 'route' => 'sistem.identitas', 'title' => 'Identitas Instansi', 'desc' => 'Logo, nama & profil instansi (kop cetakan, halaman depan)', 'roles' => HakAkses::AWAL_SISTEM, 'badge' => 'ID'],
        ['group' => 'Sistem', 'groupOrder' => 15, 'order' => 1, 'route' => 'sistem.user-control', 'title' => 'User Control', 'desc' => 'Kelola user login, role & kaitan ke karyawan', 'roles' => HakAkses::AWAL_SISTEM, 'badge' => 'USR'],
        ['group' => 'Sistem', 'groupOrder' => 15, 'order' => 2, 'route' => 'sistem.role-control', 'title' => 'Role Control', 'desc' => 'Kelola role & hak akses per program', 'roles' => HakAkses::AWAL_SISTEM, 'badge' => 'ROL'],
    ],
];
