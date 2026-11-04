<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Identitas instansi (company profile) — satu sumber untuk kop cetakan, topbar,
 * halaman depan & login. Data dari siapmst_identitas (id = 1), di-cache; bila tabel
 * belum ada / gagal dibaca → fallback config/identitas.php (.env IDENTITAS_*).
 */
class Identitas
{
    private const CACHE_KEY = 'siap.identitas';

    /** Folder unggahan logo di disk public. */
    public const FOLDER_LOGO = 'identitas';

    public static function get(): object
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            try {
                $row = DB::table('siapmst_identitas')->where('id', 1)->first();
                if ($row) {
                    return $row;
                }
            } catch (\Throwable) {
                // tabel belum dimigrasi → pakai .env
            }

            return (object) [
                'nama' => config('identitas.nama'), 'nama_singkat' => null, 'tagline' => null, 'deskripsi' => null,
                'pimpinan' => null, 'jabatan_pimpinan' => null, 'alamat' => config('identitas.alamat'),
                'kota' => config('identitas.kota'), 'kode_pos' => null, 'telp' => config('identitas.telp'),
                'fax' => config('identitas.fax'), 'email' => null, 'website' => config('identitas.website'), 'jam_operasional' => null,
                'logo' => config('identitas.logo'),
            ];
        });
    }

    public static function lupakan(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** Nama pendek untuk ruang sempit (topbar): nama singkat, atau nama lengkap. */
    public static function namaPendek(): string
    {
        $i = self::get();

        return (string) ($i->nama_singkat ?: $i->nama);
    }

    private static function diDiskPublic(?string $logo): bool
    {
        return $logo !== null && Str::startsWith($logo, self::FOLDER_LOGO . '/');
    }

    /** Path berkas logo di disk (untuk DomPDF), null bila tidak ada. */
    public static function logoPath(?string $logo = null): ?string
    {
        $logo ??= self::get()->logo;
        if (!$logo) {
            return null;
        }
        $path = self::diDiskPublic($logo) ? Storage::disk('public')->path($logo) : public_path($logo);

        return is_file($path) ? $path : null;
    }

    /** URL logo untuk halaman web, null bila tidak ada. */
    public static function logoUrl(?string $logo = null): ?string
    {
        $logo ??= self::get()->logo;
        if (!$logo || !self::logoPath($logo)) {
            return null;
        }

        return self::diDiskPublic($logo) ? asset('storage/' . $logo) : asset($logo);
    }

    /**
     * Versi ikon (gambar logo tanpa tulisan) untuk layar sempit: berkas "<nama logo> ikon.<ext>"
     * di sebelah logo. Null bila tidak ada → pemanggil memakai logo biasa.
     */
    public static function logoIkonUrl(): ?string
    {
        $logo = self::get()->logo;
        if (!$logo) {
            return null;
        }
        $ikon = preg_replace('/(\.[a-z0-9]+)$/i', ' ikon$1', $logo);

        return $ikon !== $logo ? self::logoUrl($ikon) : null;
    }

    /**
     * Logo untuk kop cetakan (model kop modul dokumen sirus-php82: logo persegi di kanan atas):
     * berkas "<nama logo> persegi.<ext>" di sebelah logo bila ada, selain itu logo biasa. Path disk untuk DomPDF.
     */
    public static function logoKopPath(): ?string
    {
        $logo = self::get()->logo;
        if (!$logo) {
            return null;
        }
        $persegi = preg_replace('/(\.[a-z0-9]+)$/i', ' persegi$1', $logo);

        return ($persegi !== $logo ? self::logoPath($persegi) : null) ?? self::logoPath($logo);
    }

    /**
     * Logo kop untuk PDF (dompdf): diperkecil (tinggi 240 px, tetap tajam di cetak 34 px) dan diratakan ke latar putih
     * tanpa kanal alfa, di-cache di storage/app/pdf-cache (kunci: path + waktu ubah berkas asli).
     * Tanpa ini dompdf memisahkan alfa PNG besar piksel demi piksel di SETIAP render (± 3 detik per PDF tanpa Imagick).
     * Gagal (GD tak ada / bukan PNG-JPG) → berkas asli.
     */
    public static function logoKopPdfPath(): ?string
    {
        $asli = self::logoKopPath();
        if (!$asli || !function_exists('imagecreatetruecolor')) {
            return $asli;
        }

        $folder = storage_path('app/pdf-cache');
        $hasil = $folder . '/logo-kop-' . md5($asli . '|' . filemtime($asli)) . '.png';
        if (is_file($hasil)) {
            return $hasil;
        }

        try {
            $sumber = match (@exif_imagetype($asli) ?: (getimagesize($asli)[2] ?? null)) {
                IMAGETYPE_PNG  => imagecreatefrompng($asli),
                IMAGETYPE_JPEG => imagecreatefromjpeg($asli),
                default        => false,
            };
            if (!$sumber) {
                return $asli;
            }
            $lebarAsli = imagesx($sumber);
            $tinggiAsli = imagesy($sumber);
            $tinggi = min(240, $tinggiAsli);
            $lebar = max(1, (int) round($lebarAsli * $tinggi / $tinggiAsli));

            $kecil = imagecreatetruecolor($lebar, $tinggi);
            imagefill($kecil, 0, 0, imagecolorallocate($kecil, 255, 255, 255));
            imagecopyresampled($kecil, $sumber, 0, 0, 0, 0, $lebar, $tinggi, $lebarAsli, $tinggiAsli);
            imagesavealpha($kecil, false);

            if (!is_dir($folder)) {
                mkdir($folder, 0775, true);
            }
            $sementara = $hasil . '.' . getmypid() . '.tmp';
            if (!imagepng($kecil, $sementara, 6) || !rename($sementara, $hasil)) {
                @unlink($sementara);
                return $asli;
            }

            return $hasil;
        } catch (\Throwable) {
            return $asli;
        }
    }

    /** Alamat lengkap satu baris: alamat, kota kode pos. */
    public static function alamatLengkap(): string
    {
        $i = self::get();
        $kota = trim(($i->kota ?? '') . ' ' . ($i->kode_pos ?? ''));
        $alamat = (string) ($i->alamat ?? '');

        // Hindari "… Tulungagung, Tulungagung" bila kota sudah tertulis di alamat.
        if ($kota !== '' && !Str::contains(mb_strtolower($alamat), mb_strtolower((string) $i->kota))) {
            return trim($alamat . ', ' . $kota, ', ');
        }

        return trim($alamat . (($i->kode_pos && !Str::contains($alamat, (string) $i->kode_pos)) ? ' ' . $i->kode_pos : ''));
    }
}
