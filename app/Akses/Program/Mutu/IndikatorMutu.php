<?php

use App\Support\HakAkses;

/*
 * Hak akses program "Indikator Mutu" — kelompok Mutu (urutan & judul: _kelompok.php).
 * Kepala ruang mengisi unitnya; admin & Komite Mutu semua unit.
 * Format & pedoman: App\Support\ProgramAkses.
 */

return [
    'nama' => 'Indikator Mutu',
    'menu' => [
        ['group' => 'Mutu', 'groupOrder' => 3.5, 'order' => 1, 'route' => 'mutu.kertas-kerja', 'title' => 'Kertas Kerja Indikator', 'desc' => 'Catat pengamatan indikator mutu per unit — capaian dihitung otomatis', 'roles' => ['admin', 'komite mutu', 'kepala ruang'], 'badge' => 'IMT'],
        ['group' => 'Mutu', 'groupOrder' => 3.5, 'order' => 2, 'route' => 'mutu.rekap-capaian', 'title' => 'Rekap Capaian Mutu', 'desc' => 'Capaian bulanan / triwulan vs target + run chart + cetak PDF', 'roles' => ['admin', 'komite mutu', 'kepala ruang'], 'badge' => 'RCM'],
    ],
    'aksiKhusus' => [
        'mutu.kunci' => ['label' => 'Kunci / buka kunci kertas kerja indikator mutu satu bulan (validasi Komite Mutu)', 'roles' => ['admin', 'komite mutu']],
    ],
];
