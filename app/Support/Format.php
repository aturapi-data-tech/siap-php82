<?php

namespace App\Support;

use Carbon\Carbon;
use DateTimeInterface;

/**
 * Format tampilan tanggal SIAP — satu standar untuk semua layar & cetakan:
 *   tanggal       → dd/mm/yyyy            (08/10/2026)
 *   bulan         → mm/yyyy               (10/2026)
 *   tanggal+waktu → dd/mm/yyyy hh24:mi:ss (08/10/2026 15:42:07)
 *   jam saja      → hh24:mi               (07:05) — jam masuk/pulang absensi
 *
 * Nilai kosong → '' (tampilan memutuskan sendiri mau '—' atau tidak).
 * Padanan Oracle: TO_CHAR(kolom, Format::ORACLE_WAKTU).
 */
class Format
{
    public const TANGGAL = 'd/m/Y';
    public const BULAN   = 'm/Y';
    public const WAKTU   = 'd/m/Y H:i:s';
    public const JAM     = 'H:i';

    public const ORACLE_TANGGAL = 'dd/mm/yyyy';
    public const ORACLE_WAKTU   = 'dd/mm/yyyy HH24:MI:SS';

    public static function tanggal(DateTimeInterface|string|null $nilai): string
    {
        return self::format($nilai, self::TANGGAL);
    }

    public static function bulan(DateTimeInterface|string|null $nilai): string
    {
        return self::format($nilai, self::BULAN);
    }

    public static function waktu(DateTimeInterface|string|null $nilai): string
    {
        return self::format($nilai, self::WAKTU);
    }

    public static function jam(DateTimeInterface|string|null $nilai): string
    {
        return self::format($nilai, self::JAM);
    }

    /** Nama hari Indonesia lengkap (Senin, Selasa, …) untuk mendampingi tanggal. */
    public static function hari(DateTimeInterface|string|null $nilai): string
    {
        return $nilai ? Carbon::parse($nilai)->locale('id')->translatedFormat('l') : '';
    }

    private static function format(DateTimeInterface|string|null $nilai, string $pola): string
    {
        if ($nilai === null || $nilai === '') {
            return '';
        }

        return ($nilai instanceof DateTimeInterface ? Carbon::instance($nilai) : Carbon::parse($nilai))->format($pola);
    }
}
