<?php

use App\Support\HakAkses;

/*
 * Hak akses program "Gaji Karyawan" — kelompok Penggajian (urutan & judul: _kelompok.php).
 * Data gaji = rahasia. Halaman gaji juga memeriksa gaji.kelola di setiap aksi.
 * Format & pedoman: App\Support\ProgramAkses.
 */

return [
    'nama' => 'Gaji Karyawan',
    'menu' => [
        ['group' => 'Penggajian', 'groupOrder' => 4, 'order' => 1, 'route' => 'keuangan.gaji-karyawan', 'title' => 'Gaji Karyawan', 'desc' => 'Slip gaji bulanan: generate dari parameter, hari kerja & rincian, final, cetak', 'roles' => HakAkses::AWAL_GAJI, 'badge' => 'GJI'],
        ['group' => 'Penggajian', 'groupOrder' => 4, 'order' => 2, 'route' => 'keuangan.pembagian-jm', 'title' => 'Pembagian Jasa Medik', 'desc' => 'Nilai dasar per pos + poin karyawan → JM per orang, terapkan ke slip gaji', 'roles' => HakAkses::AWAL_GAJI, 'badge' => 'JM'],
        ['group' => 'Penggajian', 'groupOrder' => 4, 'order' => 4, 'route' => 'keuangan.pinjaman-karyawan', 'title' => 'Pinjaman Karyawan', 'desc' => 'Buku pinjaman koperasi / bank: ketentuan sewa modal, angsuran per bulan otomatis → potong di slip gaji', 'roles' => HakAkses::AWAL_GAJI, 'badge' => 'PJM'],
    ],
    'aksiKhusus' => [
        'gaji.kelola' => ['label' => 'Kelola gaji: parameter, slip, jasa medik, pinjaman, gaji dokter, terapkan ke slip, cetak & kirim (data gaji = rahasia)', 'roles' => HakAkses::AWAL_GAJI],
    ],
];
