<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Aturan absensi yang diatur HRD di Absensi › Pengaturan Absensi — satu sumber untuk
 * kiosk, absen HP, rekap & panduan. Data dari siapmst_pengaturan_absensis (id = 1),
 * di-cache; bila tabel belum ada / gagal dibaca → nilai BAWAAN.
 */
class PengaturanAbsensi
{
    private const CACHE_KEY = 'siap.pengaturan-absensi';

    /** Nilai bawaan + batas yang boleh diisi HRD: kolom => [bawaan, min, maks]. */
    public const ATURAN = [
        'jeda_min_pulang_menit' => [10, 1, 120],
        'min_lembur_menit'      => [30, 0, 240],
        'batas_pulang_jam'      => [6, 1, 12],
        'batas_jam_lintas_hari' => [12, 1, 23],
        'maks_akurasi_meter'    => [500, 20, 5000],
    ];

    public static function get(): object
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            try {
                $row = DB::table('siapmst_pengaturan_absensis')->where('id', 1)->first();
                if ($row) {
                    return $row;
                }
            } catch (\Throwable) {
                // tabel belum dimigrasi → pakai nilai bawaan
            }

            return self::bawaan();
        });
    }

    public static function bawaan(): object
    {
        return (object) [
            ...array_map(fn($aturan) => $aturan[0], self::ATURAN),
            'absen_hp_aktif' => '1',
        ];
    }

    public static function lupakan(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** Jeda minimal masuk → pulang; scan ulang lebih cepat dianggap dobel. */
    public static function jedaMinPulangMenit(): int
    {
        return (int) self::get()->jeda_min_pulang_menit;
    }

    /** Lewat jadwal pulang kurang dari ini (mis. beres-beres) tidak dihitung lembur. */
    public static function minLemburMenit(): int
    {
        return (int) self::get()->min_lembur_menit;
    }

    /** Jendela absen pulang setelah jadwal pulang (menampung lembur panjang). */
    public static function batasPulangJam(): int
    {
        return (int) self::get()->batas_pulang_jam;
    }

    /** Absen shift lintas hari sebelum jam ini masih milik tanggal kemarin. */
    public static function batasJamLintasHari(): int
    {
        return (int) self::get()->batas_jam_lintas_hari;
    }

    /** Akurasi GPS lebih buruk dari ini ditolak (sinyal indoor / lokasi perkiraan jaringan). */
    public static function maksAkurasiMeter(): int
    {
        return (int) self::get()->maks_akurasi_meter;
    }

    public static function absenHpAktif(): bool
    {
        return (string) self::get()->absen_hp_aktif === '1';
    }
}
