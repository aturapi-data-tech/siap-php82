<?php

namespace App\Support;

/**
 * Konstanta hak akses: peran super + CENTANG AWAL yang dipakai berkas program (app/Akses/Program/<Kelompok>/*.php).
 * Pola sama dengan SIRUS & SIKEU.
 *
 * Siapa boleh apa diatur di Sistem › Role Control dengan mencentang permission per role. Nama role di sini
 * HANYA centang awal — dipakai sekali saat permission dibuat oleh `php artisan akses:sinkron` (otomatis sesudah
 * deploy). Sesudahnya yang berlaku centang Role Control: role baru (mis. "IPSRS" yang dibuat belakangan) TIDAK
 * otomatis dapat akses dari namanya — centang dulu hak-nya di Role Control.
 *
 * Definisi hak (halaman + aksi khusus) ada di berkas program — lihat App\Support\ProgramAkses. Keputusan
 * boleh/tidak: App\Support\AksesHalaman. Pemakaian aksi: blade @can('gaji.kelola'), server ->can() / Gate::authorize().
 * Menggantikan App\Support\AksiRole (daftar role per Gate) dan 'roles' literal di AppMenu.
 */
class HakAkses
{
    /** Role ini selalu boleh semua (Gate::before) — pengaman supaya tidak ada yang mengunci semua admin keluar. */
    public const PERAN_SUPER = 'Admin';

    /** Penanda 'roles' menu yang terbuka untuk SEMUA user (Absen HP, Panduan) — tak dibuat permission-nya. */
    public const SEMUA_ROLE = '*';

    /** Centang awal (nama role huruf kecil, dicocokkan tanpa beda besar-kecil & spasi ujung). */
    public const AWAL_MASTER = ['admin', 'manager umum', 'manager medis', 'supervisor penunjang', 'supervisor tu'];

    /** Data gaji = rahasia: hanya Keuangan. */
    public const AWAL_GAJI = ['admin', 'manager keuangan'];

    public const AWAL_SISTEM = ['admin'];

    /** Label hak untuk tampilan (Role Control): lihat halaman atau aksi khusus. */
    public static function label(string $permission): string
    {
        $aksi = ProgramAkses::aksiKhusus()[$permission]['label'] ?? null;
        if ($aksi !== null) {
            return $aksi;
        }
        foreach (ProgramAkses::menu() as $menu) {
            if (AksesHalaman::namaPermission($menu['route'], 'lihat') === $permission) {
                return 'Lihat halaman ' . $menu['title'];
            }
        }

        return '';
    }
}
