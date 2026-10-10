<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Resolusi lokasi gambar tanda tangan user (kolom users.myuser_ttd_image).
 *
 * Kolom itu menyimpan DUA format:
 *   - Standar baru (Kelola User): NAMA FILE saja, mis. "08052026081302.png",
 *     berkas ada di storage/app/public/UserTtd/.
 *   - Legacy: path relatif lengkap, mis. "UserTtd/abc.jpg".
 *
 * Sebelum helper ini ada, ±80 titik cetak (modul dokumen RJ/UGD/RI, viewer
 * rekam medis, form penjaminan) menyusun path sendiri dengan
 * public_path('storage/' . $nilai) — benar untuk legacy, tetapi untuk format
 * baru mencari storage/08052026081302.png yang tidak ada, sehingga file_exists
 * gagal dan TTD dokter/petugas tampil kosong di PDF. Hanya cetak rekam medis
 * RJ/UGD/e-resep (directive @ttdSrc) yang sadar dua format.
 *
 * Semua titik kini lewat sini; @ttdSrc pun mendelegasikan ke pathWeb().
 */
class TtdUser
{
    /** Folder standar baru di disk public. */
    public const FOLDER = 'UserTtd';

    /** Path relatif terhadap disk public (storage/app/public), mis. "UserTtd/x.png". */
    public static function pathDiskPublic(?string $nilai): ?string
    {
        $nilai = trim((string) $nilai);
        if ($nilai === '') {
            return null;
        }
        return str_contains($nilai, '/') ? $nilai : self::FOLDER . '/' . $nilai;
    }

    /** Path web relatif ("storage/UserTtd/x.png") — untuk @ttdSrc / <img src>. */
    public static function pathWeb(?string $nilai): string
    {
        $pathDiskPublic = self::pathDiskPublic($nilai);
        return $pathDiskPublic === null ? '' : "storage/{$pathDiskPublic}";
    }

    /** URL absolut via asset() — untuk tampilan di browser. */
    public static function url(?string $nilai): string
    {
        $pathWeb = self::pathWeb($nilai);
        return $pathWeb === '' ? '' : asset($pathWeb);
    }

    /**
     * Path sistem berkas lengkap (public_path) — untuk DomPDF & file_exists().
     * TIDAK mengecek keberadaan berkas; pemanggil tetap file_exists() sendiri
     * bila perlu. Null bila nilai kosong.
     */
    public static function pathBerkas(?string $nilai): ?string
    {
        $pathWeb = self::pathWeb($nilai);
        return $pathWeb === '' ? null : public_path($pathWeb);
    }

    /**
     * Path sistem berkas TTD dari kode user (users.myuser_code), null bila user
     * tak punya TTD atau berkasnya hilang. Satu query ke tabel users.
     */
    public static function pathBerkasDariKode(?string $kode): ?string
    {
        $pathBerkas = self::pathBerkas(self::nilaiKolomDariKode($kode));
        return ($pathBerkas !== null && file_exists($pathBerkas)) ? $pathBerkas : null;
    }

    /**
     * URL gambar TTD dari kode user untuk <img> di layar (komponen ttd-petugas),
     * '' bila user tak punya TTD atau berkasnya hilang.
     */
    public static function urlDariKode(?string $kode): string
    {
        $nilai = self::nilaiKolomDariKode($kode);
        $pathBerkas = self::pathBerkas($nilai);
        return ($pathBerkas !== null && file_exists($pathBerkas)) ? self::url($nilai) : '';
    }

    /**
     * URL gambar TTD dari emp_id karyawan (users.emp_id) — petugas laboratorium
     * di lbtxn_checkuphdrs dicatat sebagai emp_id, bukan myuser_code.
     */
    public static function urlDariEmpId(?string $empId): string
    {
        $nilai = self::nilaiKolom('emp_id', $empId);
        $pathBerkas = self::pathBerkas($nilai);
        return ($pathBerkas !== null && file_exists($pathBerkas)) ? self::url($nilai) : '';
    }

    /** Nilai mentah kolom myuser_ttd_image dari kode user; null bila kode kosong. */
    private static function nilaiKolomDariKode(?string $kode): ?string
    {
        return self::nilaiKolom('myuser_code', $kode);
    }

    /** Nilai mentah kolom myuser_ttd_image lewat kolom pencari tertentu di tabel users. */
    private static function nilaiKolom(string $kolomPencari, ?string $nilaiPencari): ?string
    {
        if (empty($nilaiPencari)) {
            return null;
        }
        return DB::table('users')->where($kolomPencari, $nilaiPencari)->value('myuser_ttd_image');
    }
}
