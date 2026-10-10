<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Tanda tangan pasien / keluarga / saksi (hasil signature-pad) — pasangan TtdUser
 * yang mengurus TTD petugas.
 *
 * Field JSON dokumen EMR (signature, signatureSaksi, keluargaSignature, ...) kini
 * menampung DUA format:
 *   - Warisan: data-URL utuh ("data:image/png;base64,...") tersimpan inline.
 *     Tidak dimigrasi; dipakai apa adanya.
 *   - Baru: REFERENSI "TTD:<TTD_NO>" ke tabel RSTXN_TTDS (docs/ddl-ttd-pasien.sql).
 *
 * Kenapa dipindah: satu TTD +-27.000 karakter. Disimpan inline, ia membengkakkan
 * CLOB dokumen (dibaca semua komponen) dan ikut snapshot Livewire tiap request —
 * dokumen multi-entri dengan 5 entri x 2 TTD = +-264 KB bolak-balik.
 *
 * Tabel belum dipasang? simpan() mengembalikan data-URL-nya lagi, jadi perilaku
 * lama (inline) berlaku otomatis — kode ini aman naik sebelum DDL dijalankan.
 */
class TtdPasien
{
    /** Awalan referensi di dalam JSON dokumen. */
    public const AWALAN_REFERENSI = 'TTD:';

    /** Gambar yang sudah dibaca di request ini — satu form bisa menampilkan TTD yang sama berulang. */
    private static array $gambarTermuat = [];

    /** true bila nilai field adalah referensi ke RSTXN_TTDS, bukan gambar inline. */
    public static function adalahReferensi(?string $nilai): bool
    {
        return is_string($nilai)
            && str_starts_with($nilai, self::AWALAN_REFERENSI)
            && ctype_digit(substr($nilai, strlen(self::AWALAN_REFERENSI)));
    }

    /**
     * Simpan hasil signature-pad, kembalikan NILAI UNTUK FIELD JSON.
     *
     * Dipanggil dari setSignature*() begitu data-URL diterima. Baris tabel tidak
     * pernah diubah: TTD ulang = baris baru. Bila tabel belum ada atau penyimpanan
     * gagal, data-URL dikembalikan apa adanya supaya TTD pasien tidak pernah hilang
     * — lebih baik berat daripada pasien diminta tanda tangan ulang.
     */
    public static function simpan(string $dataUrl, ?string $regNo = null): string
    {
        if (!str_starts_with($dataUrl, 'data:image/') || !self::tabelTersedia()) {
            return $dataUrl;
        }

        try {
            $ttdNo = (int) DB::selectOne('select SEQ_TTDS.NEXTVAL as ttd_no from dual')->ttd_no;

            DB::table('rstxn_ttds')->insert([
                'ttd_no' => $ttdNo,
                'reg_no' => filled($regNo) ? (string) $regNo : null,
                'ttd_date' => DB::raw('SYSDATE'),
                'ttd_data' => $dataUrl,
            ]);

            self::$gambarTermuat[$ttdNo] = $dataUrl;

            return self::AWALAN_REFERENSI . $ttdNo;
        } catch (\Throwable $e) {
            report($e);

            return $dataUrl;
        }
    }

    /**
     * Nilai untuk <img src="..."> — di layar maupun dompdf (keduanya menerima data-URL).
     * '' bila kosong, atau referensinya tak ditemukan.
     */
    public static function sumberGambar(?string $nilai): string
    {
        $nilai = (string) $nilai;

        if ($nilai === '') {
            return '';
        }

        // TTD warisan sirus-lite tersimpan sebagai markup <svg ...> mentah.
        if (str_starts_with($nilai, '<svg')) {
            return 'data:image/svg+xml;base64,' . base64_encode($nilai);
        }

        if (!self::adalahReferensi($nilai)) {
            return $nilai;
        }

        $ttdNo = (int) substr($nilai, strlen(self::AWALAN_REFERENSI));

        if (!array_key_exists($ttdNo, self::$gambarTermuat)) {
            self::$gambarTermuat[$ttdNo] = self::bacaGambar($ttdNo);
        }

        return self::$gambarTermuat[$ttdNo];
    }

    /** Tabel RSTXN_TTDS sudah dipasang? Di-cache supaya tak query tiap goresan. */
    public static function tabelTersedia(): bool
    {
        return Cache::remember('ttd-pasien.tabel.ada', 300, function () {
            try {
                DB::table('rstxn_ttds')->limit(1)->exists();

                return true;
            } catch (\Throwable) {
                return false;
            }
        });
    }

    /** Baca TTD_DATA lewat OracleLob (anti ORA-01555 / CLOB terpotong). '' bila baris tak ada. */
    private static function bacaGambar(int $ttdNo): string
    {
        try {
            $row = DB::table('rstxn_ttds')->select('ttd_data')->where('ttd_no', $ttdNo)->first();

            return $row ? OracleLob::read($row->ttd_data ?? null, 'rstxn_ttds', 'ttd_no', $ttdNo, 'ttd_data') : '';
        } catch (\Throwable $e) {
            report($e);

            return '';
        }
    }
}
