<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Saklar pemindahan penggajian dokter dari SIRUS ke SIAP (satu baris, id = 1) — diubah di Gaji Dokter → Struktur Gaji:
 *   sumber_struktur : SIRUS = struktur gaji dokter masih disetel di SIRUS, SIAP hanya menarik salinannya (tahap 0);
 *                     SIAP  = disetel di SIAP dan dikirim ke SIRUS lewat API (tahap 1; .env SIRUS SIAP_STRUKTUR_GAJI_DARI_SIAP=true).
 *   mesin_slip      : SIRUS = slip dihitung SIRUS, SIAP menarik snapshot (Ambil dari SIRUS); hitungan SIAP hanya pembanding (tahap 2);
 *                     SIAP  = slip dihitung SIAP dari transaksi mentah (Proses Slip); SIRUS jadi arsip (tahap 3; SIAP_GAJI_DOKTER_NONAKTIF=true).
 * Dicache per request.
 */
class PengaturanGajiDokter
{
    public const PILIHAN = ['SIRUS', 'SIAP'];
    private static ?object $cache = null;

    public static function ambil(): object
    {
        if (self::$cache === null) {
            $row = DB::table('siapmst_pengaturan_gdoks')->where('id', 1)->first();
            self::$cache = (object) [
                'sumber_struktur' => in_array($row->sumber_struktur ?? '', self::PILIHAN, true) ? $row->sumber_struktur : 'SIRUS',
                'mesin_slip' => in_array($row->mesin_slip ?? '', self::PILIHAN, true) ? $row->mesin_slip : 'SIRUS',
                'updated_at' => $row->updated_at ?? null,
            ];
        }

        return self::$cache;
    }

    public static function strukturDiSiap(): bool
    {
        return self::ambil()->sumber_struktur === 'SIAP';
    }

    public static function mesinSiap(): bool
    {
        return self::ambil()->mesin_slip === 'SIAP';
    }

    /** @return string|null pesan galat */
    public static function simpan(string $sumberStruktur, string $mesinSlip, ?int $userId): ?string
    {
        if (!in_array($sumberStruktur, self::PILIHAN, true) || !in_array($mesinSlip, self::PILIHAN, true)) {
            return 'Pilihan tidak dikenal.';
        }
        // Mesin SIAP tanpa struktur di SIAP = menghitung dengan setelan yang tidak bisa diubah di sini — tidak masuk akal.
        if ($mesinSlip === 'SIAP' && $sumberStruktur !== 'SIAP') {
            return 'Mesin slip SIAP mensyaratkan sumber struktur gaji di SIAP (tahap 1 dulu).';
        }
        $data = ['sumber_struktur' => $sumberStruktur, 'mesin_slip' => $mesinSlip, 'updated_by' => $userId, 'updated_at' => now()];
        DB::table('siapmst_pengaturan_gdoks')->where('id', 1)->exists()
            ? DB::table('siapmst_pengaturan_gdoks')->where('id', 1)->update($data)
            : DB::table('siapmst_pengaturan_gdoks')->insert([...$data, 'id' => 1, 'created_at' => now()]);
        self::$cache = null;

        return null;
    }
}
