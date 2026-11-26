<?php

use App\Support\HakAkses;

/*
 * Hak akses program "Laporan Kepegawaian" — kelompok Penggajian (urutan & judul: _kelompok.php).
 * Format & pedoman: App\Support\ProgramAkses.
 */

return [
    'nama' => 'Laporan Kepegawaian',
    'menu' => [
        ['group' => 'Penggajian', 'groupOrder' => 4, 'order' => 3, 'route' => 'keuangan.laporan-pegawai', 'title' => 'Laporan Kepegawaian', 'desc' => 'Hari kerja, cuti & jam lembur per karyawan per bulan (isian kepegawaian) → terapkan ke slip gaji', 'roles' => ['admin', 'hrd', 'manager keuangan'], 'badge' => 'LAP'],
    ],
    'aksiKhusus' => [
        'laporanPegawai.lihat' => ['label' => 'Buka Laporan Kepegawaian (hari kerja, cuti, lembur per bulan)', 'roles' => ['admin', 'hrd', 'manager keuangan']],
        'laporanPegawai.isi' => ['label' => 'Isi Laporan Kepegawaian: siapkan baris, hari kerja / cuti / lembur (terapkan ke slip = gaji.kelola)', 'roles' => ['admin', 'hrd']],
    ],
];
