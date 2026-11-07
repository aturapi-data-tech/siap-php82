<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Pengaturan penggajian dokter (satu baris, id = 1) — diubah di Gaji Dokter → Struktur Gaji → panel Mode:
 *   mesin_slip : SIRUS = slip dihitung SIRUS, SIAP menarik hasilnya sebagai snapshot (Ambil dari SIRUS); hitungan SIAP hanya pembanding (Uji hitung);
 *                SIAP  = slip dihitung SIAP sendiri dari transaksi SIRUS + struktur gaji SIAP (Proses Slip); SIRUS tinggal pemasok transaksi.
 * Struktur gaji dokter selalu disetel di SIAP (GajiDokterStruktur). Dicache per request.
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
                'mesin_slip' => in_array($row->mesin_slip ?? '', self::PILIHAN, true) ? $row->mesin_slip : 'SIRUS',
                'updated_at' => $row->updated_at ?? null,
            ];
        }

        return self::$cache;
    }

    public static function mesinSiap(): bool
    {
        return self::ambil()->mesin_slip === 'SIAP';
    }

    /** @return string|null pesan galat */
    public static function simpan(string $mesinSlip, ?int $userId): ?string
    {
        if (!in_array($mesinSlip, self::PILIHAN, true)) {
            return 'Pilihan tidak dikenal.';
        }
        $data = ['mesin_slip' => $mesinSlip, 'updated_by' => $userId, 'updated_at' => now()];
        DB::table('siapmst_pengaturan_gdoks')->where('id', 1)->exists()
            ? DB::table('siapmst_pengaturan_gdoks')->where('id', 1)->update($data)
            : DB::table('siapmst_pengaturan_gdoks')->insert([...$data, 'id' => 1, 'created_at' => now()]);
        self::$cache = null;

        return null;
    }
}
