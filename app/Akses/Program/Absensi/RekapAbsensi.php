<?php

use App\Support\HakAkses;

/*
 * Hak akses program "Rekap & Pengaturan Absensi" — kelompok Absensi (urutan & judul: _kelompok.php).
 * Format & pedoman: App\Support\ProgramAkses.
 */

return [
    'nama' => 'Rekap & Pengaturan Absensi',
    'menu' => [
        ['group' => 'Absensi', 'groupOrder' => 2, 'order' => 2, 'route' => 'absensi.rekap-harian', 'title' => 'Rekap Harian', 'desc' => 'Kehadiran per tanggal + isi Izin/Sakit/Cuti/Alpa', 'roles' => ['admin', 'hrd', 'manager keuangan'], 'badge' => 'HRI'],
        ['group' => 'Absensi', 'groupOrder' => 2, 'order' => 3, 'route' => 'absensi.rekap-bulanan', 'title' => 'Rekap Bulanan', 'desc' => 'Jumlah H/T/I/S/C/A per karyawan per bulan + nilai lembur', 'roles' => ['admin', 'hrd', 'manager keuangan', 'supervisor unit'], 'badge' => 'BLN'],
        ['group' => 'Absensi', 'groupOrder' => 2, 'order' => 4, 'route' => 'absensi.pengaturan', 'title' => 'Pengaturan Absensi', 'desc' => 'Satu dasbor: aturan absen, shift, titik lokasi HP & lokasi karyawan', 'roles' => ['admin', 'hrd'], 'badge' => 'SET'],
    ],
    'aksiKhusus' => [
        'absensi.lihat' => ['label' => 'Lihat absensi SEMUA unit — tanpa ini (mis. Supervisor Unit) dikunci ke unit karyawan yang terhubung ke akunnya', 'roles' => ['admin', 'hrd', 'manager keuangan']],
        'absensi.kelola' => ['label' => 'Ubah Rekap Harian: izin, sakit, cuti, koreksi jam, Generate Libur/Alpa', 'roles' => ['admin', 'hrd']],
        'lembur.nilai' => ['label' => 'Nilai lembur dihitung / tidak (tanpa absensi.lihat: hanya unitnya)', 'roles' => ['admin', 'hrd', 'supervisor unit']],
        'absensi.lihatFoto' => ['label' => 'Buka foto selfie absen HP karyawan lain (pemilik absen selalu boleh melihat fotonya sendiri)', 'roles' => ['admin', 'hrd']],
    ],
];
