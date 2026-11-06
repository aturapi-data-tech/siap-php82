<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Pengaturan gaji (satu baris, id = 1) — ketentuan BPJS yang dipakai GajiKaryawan::hitungUlang():
 *   umk        : dasar JKN karyawan (bila master tidak mengisi dasar sendiri) & batas bawah upah hitung JP
 *   persen_jkn : % BPJS Kesehatan bagian karyawan per jiwa (karyawan + tiap keluarga tambahan)
 *   persen_jp  : % Jaminan Pensiun bagian karyawan dari upah bulan itu
 * Diubah di Gaji Karyawan → Ketentuan BPJS (gaji.kelola). Dicache per request.
 */
class PengaturanGaji
{
    public const KOLOM = ['umk', 'persen_jkn', 'persen_jp', 'keterangan'];

    private static ?object $cache = null;

    public static function ambil(): object
    {
        if (self::$cache === null) {
            $row = DB::table('siapmst_pengaturan_gajis')->where('id', 1)->first();
            self::$cache = (object) [
                'umk'        => (float) ($row->umk ?? 0),
                'persen_jkn' => (float) ($row->persen_jkn ?? 1),
                'persen_jp'  => (float) ($row->persen_jp ?? 1),
                'keterangan' => (string) ($row->keterangan ?? ''),
                'updated_at' => $row->updated_at ?? null,
            ];
        }

        return self::$cache;
    }

    /** @return string|null pesan galat */
    public static function simpan(array $data, ?int $userId): ?string
    {
        if (!is_numeric($data['umk'] ?? null) || (float) $data['umk'] < 0 || (float) $data['umk'] > 999999999) {
            return 'UMK harus angka ≥ 0.';
        }
        foreach (['persen_jkn' => 'Persen JKN', 'persen_jp' => 'Persen JP'] as $kolom => $label) {
            if (!is_numeric($data[$kolom] ?? null) || (float) $data[$kolom] < 0 || (float) $data[$kolom] > 20) {
                return "{$label} harus 0–20.";
            }
        }
        $baris = ['umk' => round((float) $data['umk'], 2), 'persen_jkn' => round((float) $data['persen_jkn'], 2), 'persen_jp' => round((float) $data['persen_jp'], 2),
            'keterangan' => mb_substr(trim((string) ($data['keterangan'] ?? '')), 0, 200) ?: null, 'updated_by' => $userId, 'updated_at' => now()];
        DB::table('siapmst_pengaturan_gajis')->where('id', 1)->exists()
            ? DB::table('siapmst_pengaturan_gajis')->where('id', 1)->update($baris)
            : DB::table('siapmst_pengaturan_gajis')->insert([...$baris, 'id' => 1, 'created_at' => now()]);
        self::$cache = null;

        return null;
    }
}
