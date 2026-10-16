<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

/**
 * AppMenu — sumber tunggal definisi menu aplikasi (dashboard + sidebar).
 *
 * - `all()` mengembalikan semua entri (defensive: skip route yang tidak terdaftar)
 * - `forRoles($roles)` filter by role names (lowercase)
 * - `grouped(array $roles)` filter + sort + group by 'group' untuk render UI.
 *
 * Tambah/edit menu di method `definitions()` saja. Dashboard & sidebar otomatis ikut.
 */
class AppMenu
{
    /** Role yang berhak melihat semua menu Master. */
    private const MASTER_ROLES = ['admin', 'manager umum', 'manager medis', 'supervisor penunjang', 'supervisor tu'];

    /**
     * Penanda 'roles' untuk menu yang boleh dilihat SEMUA role (juga user tanpa role).
     * Dipakai menu lintas unit seperti Formulir Manual Down Time — saat SIMRS
     * bermasalah, siapa pun yang masih bisa membuka aplikasi harus dapat
     * mengunduh formulir cadangannya.
     */
    public const SEMUA_ROLE = '*';

    /** @return array<int, array<string, mixed>> */
    public static function all(): array
    {
        $entry = function (array $menu): ?array {
            if (!Route::has($menu['route'])) {
                return null;
            }
            $menu['href'] = route($menu['route'], $menu['params'] ?? []);
            return $menu;
        };

        return array_values(array_filter(self::definitions($entry)));
    }

    /**
     * Filter entries by role names (case-insensitive).
     * Entri ber-roles ['*'] (SEMUA_ROLE) selalu lolos, apa pun role user.
     *
     * @param  array<int, string>  $userRoles
     * @return array<int, array<string, mixed>>
     */
    public static function forRoles(array $userRoles): array
    {
        // trim() penting: nama role di DB pernah mengandung trailing space ('Manager Umum')
        // → strtolower saja tidak match dan seluruh menu role tsb hilang.
        $roles = array_map(fn($role) => trim(strtolower($role)), $userRoles);

        return array_values(array_filter(
            self::all(),
            fn($menu) => in_array(self::SEMUA_ROLE, $menu['roles'], true) || !empty(array_intersect($menu['roles'], $roles)),
        ));
    }

    /**
     * Sorted + grouped by 'group'. Cocok untuk render sidebar/dashboard.
     *
     * @param  array<int, string>  $userRoles
     */
    public static function grouped(array $userRoles): Collection
    {
        return collect(self::forRoles($userRoles))
            ->sortBy([['groupOrder', 'asc'], ['order', 'asc']])
            ->groupBy('group');
    }

    /**
     * Definisi semua menu. Helper $entry guard route existence + inject 'href'.
     * Setiap entry: ['group','groupOrder','order','route','title','desc','roles','badge'].
     *
     * @param  callable(array):?array  $entry
     * @return array<int, ?array<string, mixed>>
     */
    private static function definitions(callable $entry): array
    {
        $masterRoles = self::MASTER_ROLES;

        return [
            // ── Master ─────────────────────────────────────────────────
            $entry(['group' => 'Master', 'groupOrder' => 1, 'order' => 1, 'route' => 'master.karyawan', 'title' => 'Master Karyawan', 'desc' => 'Data karyawan, penanda nakes & status aktif', 'roles' => $masterRoles, 'badge' => 'KRY']),
            $entry(['group' => 'Master', 'groupOrder' => 1, 'order' => 1.5, 'route' => 'master.unit-kerja', 'title' => 'Master Unit Kerja', 'desc' => 'Unit / ruang karyawan — jadwal & shift per unit', 'roles' => [...$masterRoles, 'hrd'], 'badge' => 'UNT']),
            $entry(['group' => 'Master', 'groupOrder' => 1, 'order' => 2, 'route' => 'master.shift', 'title' => 'Master Shift', 'desc' => 'Jam kerja shift untuk absensi', 'roles' => [...$masterRoles, 'hrd'], 'badge' => 'SFT']),

            $entry(['group' => 'Master', 'groupOrder' => 1, 'order' => 2.5, 'route' => 'master.lokasi-absen', 'title' => 'Lokasi Absen HP', 'desc' => 'Titik RS / klinik + radius untuk absen GPS & selfie', 'roles' => [...$masterRoles, 'hrd'], 'badge' => 'LOK']),
            $entry(['group' => 'Master', 'groupOrder' => 1, 'order' => 3, 'route' => 'master.ruang', 'title' => 'Master Ruang', 'desc' => 'Ruang / unit — lokasi alat & awalan kode alat', 'roles' => [...$masterRoles, 'ipsrs'], 'badge' => 'RNG']),

            // ── Aset ───────────────────────────────────────────────────
            $entry(['group' => 'Aset', 'groupOrder' => 3, 'order' => 1, 'route' => 'master.alat', 'title' => 'Inventaris Alat', 'desc' => 'Alat medis & non medis per ruang + status kalibrasi', 'roles' => ['admin', 'ipsrs'], 'badge' => 'INV']),
            $entry(['group' => 'Aset', 'groupOrder' => 3, 'order' => 2, 'route' => 'aset.jadwal-kalibrasi', 'title' => 'Jadwal Kalibrasi', 'desc' => 'Program kalibrasi tahunan alat (MFK 7) + cetak PDF', 'roles' => ['admin', 'ipsrs'], 'badge' => 'KAL']),
            $entry(['group' => 'Aset', 'groupOrder' => 3, 'order' => 3, 'route' => 'aset.pemeliharaan-alat', 'title' => 'Pemeliharaan Alat', 'desc' => 'Entry & riwayat pemeriksaan alat harian / mingguan (B / P / R) + cetak checklist', 'roles' => ['admin', 'ipsrs', 'kepala ruang'], 'badge' => 'PM']),
            $entry(['group' => 'Aset', 'groupOrder' => 3, 'order' => 4, 'route' => 'aset.perbaikan-alat', 'title' => 'Perbaikan Alat', 'desc' => 'Lapor kerusakan & proses perbaikan (teknisi / vendor) + laporan', 'roles' => ['admin', 'ipsrs', 'kepala ruang'], 'badge' => 'PBK']),

            // ── Absensi ────────────────────────────────────────────────
            $entry(['group' => 'Absensi', 'groupOrder' => 2, 'order' => 0, 'route' => 'absensi.jadwal-dinas', 'title' => 'Jadwal Dinas', 'desc' => 'Jadwal masuk kerja bulanan (P / S / M / I / C) + cetak', 'roles' => ['admin', 'hrd', 'kepala ruang'], 'badge' => 'JDW']),
            $entry(['group' => 'Absensi', 'groupOrder' => 2, 'order' => 1, 'route' => 'absensi.kiosk', 'title' => 'Kiosk Absensi', 'desc' => 'Layar absen masuk/pulang — ketik/scan ID karyawan', 'roles' => ['admin', 'hrd', 'kiosk'], 'badge' => 'ABS']),
            $entry(['group' => 'Absensi', 'groupOrder' => 2, 'order' => 1.5, 'route' => 'absensi.hp', 'title' => 'Absen HP', 'desc' => 'Absen masuk/pulang sendiri dari HP — GPS + selfie', 'roles' => [self::SEMUA_ROLE], 'badge' => 'HP']),
            $entry(['group' => 'Absensi', 'groupOrder' => 2, 'order' => 2, 'route' => 'absensi.rekap-harian', 'title' => 'Rekap Harian', 'desc' => 'Kehadiran per tanggal + isi Izin/Sakit/Cuti/Alpa', 'roles' => ['admin', 'hrd'], 'badge' => 'HRI']),
            $entry(['group' => 'Absensi', 'groupOrder' => 2, 'order' => 3, 'route' => 'absensi.rekap-bulanan', 'title' => 'Rekap Bulanan', 'desc' => 'Jumlah H/T/I/S/C/A per karyawan per bulan', 'roles' => ['admin', 'hrd'], 'badge' => 'BLN']),

            $entry(['group' => 'Absensi', 'groupOrder' => 2, 'order' => 4, 'route' => 'absensi.pengaturan', 'title' => 'Pengaturan Absensi', 'desc' => 'Satu dasbor: aturan absen, shift, titik lokasi HP & lokasi karyawan', 'roles' => ['admin', 'hrd'], 'badge' => 'SET']),

            // ── Panduan (semua user) ───────────────────────────────────
            $entry(['group' => 'Panduan', 'groupOrder' => 90, 'order' => 1, 'route' => 'panduan.absensi', 'title' => 'Panduan Absensi', 'desc' => 'Cara pakai jadwal dinas, kiosk, rekap & lembur', 'roles' => [self::SEMUA_ROLE], 'badge' => '?']),
            $entry(['group' => 'Panduan', 'groupOrder' => 90, 'order' => 2, 'route' => 'panduan.aset', 'title' => 'Panduan Aset', 'desc' => 'Cara pakai inventaris, kalibrasi, pemeliharaan, perbaikan', 'roles' => [self::SEMUA_ROLE], 'badge' => '?']),

            // ── Sistem ─────────────────────────────────────────────────
            $entry(['group' => 'Sistem', 'groupOrder' => 15, 'order' => 0, 'route' => 'sistem.identitas', 'title' => 'Identitas Instansi', 'desc' => 'Logo, nama & profil instansi (kop cetakan, halaman depan)', 'roles' => ['admin'], 'badge' => 'ID']),
            $entry(['group' => 'Sistem', 'groupOrder' => 15, 'order' => 1, 'route' => 'sistem.user-control', 'title' => 'User Control', 'desc' => 'Kelola user login, role & kaitan ke karyawan', 'roles' => ['admin'], 'badge' => 'USR']),
            $entry(['group' => 'Sistem', 'groupOrder' => 15, 'order' => 2, 'route' => 'sistem.role-control', 'title' => 'Role Control', 'desc' => 'Kelola role & permission', 'roles' => ['admin'], 'badge' => 'ROL']),
        ];
    }
}
