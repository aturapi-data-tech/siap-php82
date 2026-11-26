<?php

use App\Support\HakAkses;

/*
 * Hak akses program "Panduan" — kelompok Sistem (urutan & judul: _kelompok.php).
 * Panduan Absensi & Aset terbuka untuk semua user login — tak ada centang.
 * Format & pedoman: App\Support\ProgramAkses.
 */

return [
    'nama' => 'Panduan',
    'menu' => [
        ['group' => 'Panduan', 'groupOrder' => 90, 'order' => 1, 'route' => 'panduan.absensi', 'title' => 'Panduan Absensi', 'desc' => 'Cara pakai jadwal dinas, kiosk, rekap & lembur', 'roles' => [HakAkses::SEMUA_ROLE], 'badge' => '?'],
        ['group' => 'Panduan', 'groupOrder' => 90, 'order' => 2, 'route' => 'panduan.aset', 'title' => 'Panduan Aset', 'desc' => 'Cara pakai inventaris, kalibrasi, pemeliharaan, perbaikan', 'roles' => [HakAkses::SEMUA_ROLE], 'badge' => '?'],
        ['group' => 'Panduan', 'groupOrder' => 90, 'order' => 3, 'route' => 'panduan.gaji', 'title' => 'Panduan Gaji', 'desc' => 'Cara pakai parameter gaji, slip, jasa medik, laporan kepegawaian, pinjaman karyawan, form bagian Excel, PPh, cetak & kirim WA', 'roles' => HakAkses::AWAL_GAJI, 'badge' => '?'],
        ['group' => 'Panduan', 'groupOrder' => 90, 'order' => 4, 'route' => 'panduan.struktur-db', 'title' => 'Struktur Database', 'desc' => 'Gambar relasi antar tabel (ERD), daftar kolom & SQL struktur siap eksekusi', 'roles' => HakAkses::AWAL_SISTEM, 'badge' => 'ERD'],
        ['group' => 'Panduan', 'groupOrder' => 90, 'order' => 5, 'route' => 'panduan.sirus-api', 'title' => 'Bridging SIRUS', 'desc' => 'SIAP sebagai client API baca SIRUS (slip & transaksi gaji dokter) — setup .env & akun, token, uji sambungan, penanganan masalah', 'roles' => HakAkses::AWAL_GAJI, 'badge' => 'API'],
    ],
    'aksiKhusus' => [
        'sistem.strukturDb' => ['label' => 'Buka detail Struktur Database & unduh SQL struktur (detail teknis skema)', 'roles' => HakAkses::AWAL_SISTEM],
    ],
];
