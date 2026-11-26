<?php

/*
 * Hak akses kelompok Aset — SATU BERKAS PER PROGRAM di folder ini (lihat App\Support\ProgramAkses).
 *
 *  'menu'       : entri menu sidebar + hak Lihat halaman (`{route}.lihat`).
 *  'aksiKhusus' : aksi di dalam halaman (belum ada) — nama Gate => label, roles awal.
 *
 * roles = CENTANG AWAL saja; sesudah permission dibuat (otomatis saat deploy) yang berlaku centang Role Control.
 */

return [
    'judul' => 'Aset',
    // Urutan = urutan tampil di Role Control. Nama berkas program di folder ini (tanpa .php).
    'program' => [
        'InventarisKalibrasi',
        'PemeliharaanPerbaikan',
    ],
];
