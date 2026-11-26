<?php

namespace App\Support;

use RuntimeException;

/**
 * SUMBER TUNGGAL definisi hak akses — SATU BLOK PER PROGRAM (pola yang sama dengan SIRUS).
 *
 * Satu program (mis. "Gaji Karyawan") = SATU BERKAS, dikelompokkan per folder di
 * app/Akses/Program/<Kelompok>/:
 *
 *   Penggajian/_kelompok.php              → ['judul' => 'Penggajian', 'program' => ['GajiKaryawan', ...]]
 *   Penggajian/GajiKaryawan.php           → [
 *       'nama'       => 'Gaji Karyawan',                                     // judul di Role Control
 *       'menu'       => [ [entri menu: route, title, roles, ...] ],        // → AppMenu + hak Lihat halaman
 *       'aksiKhusus' => [ 'gaji.kelola' => ['label' => ..., 'roles' => [...]] ],     // → Gate / @can
 *   ]
 *
 * 'roles' = CENTANG AWAL saja (nama role huruf kecil): dipakai sekali saat permission dibuat oleh
 * akses:sinkron (otomatis sesudah deploy, App\Support\SinkronAksesOtomatis). Sesudahnya yang berlaku
 * centang Sistem › Role Control. HakAkses::SEMUA_ROLE = halaman terbuka untuk semua user login.
 *
 * Menambah halaman/aksi: tulis di berkas programnya. Program baru: buat berkasnya + daftarkan namanya di
 * 'program' _kelompok.php (urutan tampil). Pasang @can / Gate::authorize di komponen, deploy — permission
 * + centang awal dibuat otomatis. Pedoman: CLAUDE.md § Hak akses.
 */
class ProgramAkses
{
    /** Urutan kelompok = urutan tampil di Role Control. Nama folder di app/Akses/Program. */
    private const BERKAS = [
        'Master',
        'Absensi',
        'Aset',
        'Mutu',
        'Penggajian',
        'Sistem',
    ];

    /** @var array<int, array{judul: string, program: array<string, array>}>|null */
    private static ?array $kelompok = null;

    private static ?array $aksiKhusus = null;

    /**
     * Semua kelompok beserta programnya, urut sesuai BERKAS.
     *
     * @return array<int, array{judul: string, program: array<string, array{menu?: array, aksiKhusus?: array}>}>
     */
    public static function kelompok(): array
    {
        if (self::$kelompok !== null) {
            return self::$kelompok;
        }

        $kelompok = [];
        foreach (self::BERKAS as $folder) {
            $kepala = require app_path("Akses/Program/{$folder}/_kelompok.php");
            if (!is_array($kepala) || !isset($kepala['judul'], $kepala['program']) || !is_array($kepala['program'])) {
                throw new RuntimeException("Akses/Program/{$folder}/_kelompok.php tidak berisi 'judul' & daftar 'program'.");
            }

            $program = [];
            foreach ($kepala['program'] as $berkas) {
                $isi = require app_path("Akses/Program/{$folder}/{$berkas}.php");
                if (!is_array($isi) || !is_string($isi['nama'] ?? null)) {
                    throw new RuntimeException("Akses/Program/{$folder}/{$berkas}.php tidak berisi 'nama' program.");
                }
                if (isset($program[$isi['nama']])) {
                    throw new RuntimeException("Program '{$isi['nama']}' dua kali di kelompok {$kepala['judul']}.");
                }
                $nama = $isi['nama'];
                unset($isi['nama']);
                $program[$nama] = $isi;
            }

            $kelompok[] = ['judul' => $kepala['judul'], 'program' => $program];
        }

        return self::$kelompok = $kelompok;
    }

    /**
     * Entri menu semua program (format AppMenu), dalam urutan definisi.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function menu(): array
    {
        $menu = [];
        foreach (self::kelompok() as $kelompok) {
            foreach ($kelompok['program'] as $program) {
                foreach ($program['menu'] ?? [] as $entri) {
                    $menu[] = $entri;
                }
            }
        }

        return $menu;
    }

    /**
     * Katalog aksi khusus: nama Gate => [label, roles, program, kelompok].
     *
     * @return array<string, array{label: string, roles: array<int, string>, program: string, kelompok: string}>
     */
    public static function aksiKhusus(): array
    {
        if (self::$aksiKhusus !== null) {
            return self::$aksiKhusus;
        }

        $aksiKhusus = [];
        foreach (self::kelompok() as $kelompok) {
            foreach ($kelompok['program'] as $namaProgram => $program) {
                foreach ($program['aksiKhusus'] ?? [] as $nama => $aksi) {
                    if (isset($aksiKhusus[$nama])) {
                        throw new RuntimeException("Aksi khusus '{$nama}' didefinisikan dua kali.");
                    }
                    $aksiKhusus[$nama] = $aksi + ['program' => $namaProgram, 'kelompok' => $kelompok['judul']];
                }
            }
        }

        return self::$aksiKhusus = $aksiKhusus;
    }
}
