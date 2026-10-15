<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Absen lewat HP: validasi posisi GPS terhadap titik lokasi kerja + simpan selfie.
 *
 * - Jarak dihitung di SERVER (haversine) dari koordinat yang dikirim HP — HP tidak
 *   pernah memutuskan sendiri "sudah di dalam radius".
 * - Karyawan ber-emp_lokasi_id hanya dicek terhadap titik itu; yang kosong boleh di
 *   titik aktif mana pun (dipilih yang terdekat).
 * - Selfie disimpan di disk 'local' (privat) — tidak bisa dibuka lewat URL publik.
 */
class LokasiAbsen
{
    /**
     * Batas ukuran selfie (sesudah base64 di-decode). HP mengecilkan foto ke ±640px (±50–100 KB);
     * base64 menambah ±37%, jadi batas ini tetap di bawah livewire payload.max_size (1 MB).
     */
    public const MAKS_FOTO_BYTE = 600_000;

    private const JARI_JARI_BUMI_METER = 6_371_000;

    /** Jarak dua koordinat (meter) — rumus haversine. */
    public static function jarakMeter(float $latitude1, float $longitude1, float $latitude2, float $longitude2): float
    {
        $selisihLatitude = deg2rad($latitude2 - $latitude1);
        $selisihLongitude = deg2rad($longitude2 - $longitude1);

        $haversine = sin($selisihLatitude / 2) ** 2
            + cos(deg2rad($latitude1)) * cos(deg2rad($latitude2)) * sin($selisihLongitude / 2) ** 2;

        return self::JARI_JARI_BUMI_METER * 2 * atan2(sqrt($haversine), sqrt(1 - $haversine));
    }

    /**
     * Titik lokasi yang berlaku untuk karyawan + jarak posisinya ke titik terdekat.
     *
     * @return array{lokasi: ?object, jarakMeter: ?int, dalamRadius: bool, pesan: ?string}
     */
    public static function periksa(?string $lokasiKaryawan, float $latitude, float $longitude, float $akurasiMeter): array
    {
        $titikList = DB::table('siapmst_lokasi_absens')
            ->where('active_status', '1')
            ->when($lokasiKaryawan, fn($query) => $query->where('lokasi_id', $lokasiKaryawan))
            ->get();

        if ($titikList->isEmpty()) {
            return ['lokasi' => null, 'jarakMeter' => null, 'dalamRadius' => false,
                'pesan' => $lokasiKaryawan
                    ? 'Titik lokasi kerja Anda non-aktif. Hubungi HRD.'
                    : 'Belum ada titik lokasi absen. Hubungi HRD.'];
        }

        $terdekat = $titikList
            ->map(fn($titik) => [$titik, self::jarakMeter((float) $titik->latitude, (float) $titik->longitude, $latitude, $longitude)])
            ->sortBy(fn($pasangan) => $pasangan[1])
            ->first();
        [$lokasi, $jarak] = $terdekat;
        $jarakMeter = (int) round($jarak);

        if ($akurasiMeter > PengaturanAbsensi::maksAkurasiMeter()) {
            return ['lokasi' => $lokasi, 'jarakMeter' => $jarakMeter, 'dalamRadius' => false,
                'pesan' => 'Sinyal GPS kurang akurat (±' . (int) round($akurasiMeter) . ' m). Aktifkan mode lokasi akurasi tinggi, keluar ke area terbuka, lalu coba lagi.'];
        }

        if ($jarakMeter > (int) $lokasi->radius_meter) {
            return ['lokasi' => $lokasi, 'jarakMeter' => $jarakMeter, 'dalamRadius' => false,
                'pesan' => 'Anda berada ' . self::labelJarak($jarakMeter) . ' dari ' . $lokasi->lokasi_name
                    . '. Absen hanya bisa dalam radius ' . self::labelJarak((int) $lokasi->radius_meter) . '.'];
        }

        return ['lokasi' => $lokasi, 'jarakMeter' => $jarakMeter, 'dalamRadius' => true, 'pesan' => null];
    }

    /**
     * Simpan selfie dari data URL JPEG kamera HP.
     * @return ?string path di disk 'local', null bila data bukan JPEG valid / terlalu besar.
     */
    public static function simpanFoto(string $dataUrl, string $empId, string $jenis): ?string
    {
        if (!str_starts_with($dataUrl, 'data:image/jpeg;base64,')) {
            return null;
        }

        $isiFoto = base64_decode(substr($dataUrl, strlen('data:image/jpeg;base64,')), true);
        if ($isiFoto === false || strlen($isiFoto) > self::MAKS_FOTO_BYTE || !str_starts_with($isiFoto, "\xFF\xD8\xFF")) {
            return null;
        }

        $path = 'absensi/' . now()->format('Y/m') . '/' . Str::slug($empId) . '-' . $jenis . '-' . now()->format('Ymd-His') . '-' . Str::random(6) . '.jpg';
        Storage::disk('local')->put($path, $isiFoto);

        return $path;
    }

    /** "850 m" / "1,4 km" */
    public static function labelJarak(int $meter): string
    {
        return $meter < 1000 ? $meter . ' m' : number_format($meter / 1000, 1, ',', '.') . ' km';
    }
}
