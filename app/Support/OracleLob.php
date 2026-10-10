<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Pembaca CLOB Oracle yang tahan ORA-01555 / ORA-22924 ("snapshot too old").
 *
 * Query list (mis. pelayanan-rj / daftar-rj) men-SELECT kolom CLOB sebagai
 * locator lazy (OCILob); pemanggilan ->load() ditunda sampai .map(). Sementara
 * itu UPDATE pada kolom yang sama (mis. simpan SKDP save-all) me-recycle versi
 * LOB lama, sehingga snapshot baca locator lama tak bisa direkonstruksi lagi
 * → ORA-01555 / ORA-22924 saat ->load().
 *
 * Tidak bisa diakali dengan materialize CLOB ke VARCHAR2 di SQL (TO_CHAR /
 * DBMS_LOB.SUBSTR) karena sebagian JSON EMR > 32767 byte → terpotong & rusak.
 *
 * Strategi: coba baca locator; bila gagal karena snapshot-too-old, ambil ULANG
 * nilai kolom lewat statement segar lalu baca langsung (jendela baca minimal).
 *
 * Helper statis (bukan trait) supaya bisa dipakai lintas komponen tanpa risiko
 * tabrakan method trait — pola yang sama seperti [[LogText]].
 */
class OracleLob
{
    /** Normalisasi nilai CLOB (OCILob | resource | string | null) ke string. */
    public static function toString(mixed $raw): string
    {
        if ($raw === null) {
            return '';
        }
        if (\is_object($raw) && method_exists($raw, 'load')) {
            return (string) $raw->load();
        }
        if (\is_resource($raw)) {
            return (string) stream_get_contents($raw);
        }
        return (string) $raw;
    }

    /**
     * Baca CLOB jadi string; retry fetch segar bila kena snapshot-too-old.
     *
     * @param  mixed   $raw     nilai kolom dari row hasil select (locator/string)
     * @param  string  $table   tabel sumber untuk re-fetch
     * @param  string  $keyCol  kolom kunci unik (mis. rj_no)
     * @param  mixed   $keyVal  nilai kunci row ini
     * @param  string  $lobCol  nama kolom CLOB
     */
    public static function read(mixed $raw, string $table, string $keyCol, $keyVal, string $lobCol): string
    {
        try {
            return self::toString($raw);
        } catch (\Throwable $e) {
            if (! self::isSnapshotTooOld($e)) {
                throw $e;
            }
        }

        // Snapshot too old → ambil ulang lewat statement segar, baca langsung.
        try {
            $fresh = DB::table($table)->where($keyCol, $keyVal)->value($lobCol);

            return self::toString($fresh);
        } catch (\Throwable $e) {
            // Jangan jatuhkan seluruh list hanya karena 1 row LOB gagal dibaca.
            return '';
        }
    }

    private static function isSnapshotTooOld(\Throwable $e): bool
    {
        $msg = $e->getMessage();

        return str_contains($msg, 'ORA-01555') || str_contains($msg, 'ORA-22924');
    }
}
