<?php

namespace App\Support;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Menjalankan `php artisan akses:sinkron` OTOMATIS sesudah deploy — tidak perlu diingat manual.
 *
 * Cara kerja: tanda = waktu-ubah + ukuran berkas definisi hak akses (berkas program di
 * app/Akses/Program/<Kelompok>/*.php, HakAkses, ProgramAkses — termasuk berkas program baru/terhapus).
 * Tanda yang terakhir berhasil disinkron disimpan di storage/framework/akses-sinkron.tanda
 * (berkas, bukan cache — tak ikut hilang/berbeda per driver cache). Request pertama yang
 * melihat tandanya berubah (habis git pull) menjalankan sinkron sekali; request lain yang
 * datang bersamaan tidak menunggu (kunci flock non-blocking) dan jalan seperti biasa.
 *
 * Aman diulang: sinkron MEMBUAT permission yang belum ada + centang awalnya dan MENGHAPUS
 * permission yang tak lagi ada di definisi (dicatat ke log 'akses'); centang yang sudah diatur
 * admin di Role Control tidak disentuh (tanpa --ulang).
 *
 * Gagal (mis. DB putus) → dicatat ke log, dicoba lagi paling cepat JEDA_GAGAL detik kemudian
 * — supaya error tidak diulang di setiap request.
 */
class SinkronAksesOtomatis
{
    /** Berkas yang isinya menentukan daftar permission. Ubah salah satunya = sinkron ulang. */
    private const BERKAS_DEFINISI = [
        'app/Support/HakAkses.php',
        'app/Support/ProgramAkses.php',
    ];

    /** Blok program per kelompok — dipantau satu per satu (berkas baru/terhapus ikut mengubah tanda). */
    private const POLA_BERKAS_PROGRAM = 'app/Akses/Program/*/*.php';

    private const JEDA_GAGAL = 600;

    /**
     * true selama sinkron otomatis memegang kunci dan menjalankan akses:sinkron di proses ini — perintahnya
     * tidak boleh mengambil kunci lagi (flock kedua di proses yang sama akan menunggu dirinya sendiri).
     */
    public static bool $sedangMenyinkron = false;

    public static function pastikan(): void
    {
        $tanda = self::tandaSekarang();
        $isi = @file_get_contents(self::berkasTanda());

        if ($isi === $tanda) {
            return;
        }
        // "gagal:<tanda>:<waktu>" — tunggu jeda sebelum mencoba lagi tanda yang sama.
        if (is_string($isi) && str_starts_with($isi, 'gagal:' . $tanda . ':')
            && time() - (int) substr($isi, strlen('gagal:' . $tanda . ':')) < self::JEDA_GAGAL) {
            return;
        }

        $kunci = self::bukaKunci();
        if ($kunci === false || !flock($kunci, LOCK_EX | LOCK_NB)) {
            return; // request lain sedang menyinkron
        }

        try {
            // Cek ulang di dalam kunci: request sebelumnya mungkin baru saja selesai.
            if (@file_get_contents(self::berkasTanda()) === $tanda) {
                return;
            }

            self::$sedangMenyinkron = true;
            try {
                $kode = Artisan::call('akses:sinkron');
            } finally {
                self::$sedangMenyinkron = false;
            }
            $keluaran = trim(Artisan::output());

            if ($kode === 0) {
                self::tulisTanda();
                // Channel 'akses' hanya menampung warning ke atas → sukses dicatat di log utama.
                Log::info('akses:sinkron otomatis', ['hasil' => $keluaran]);
            } else {
                self::tulis('gagal:' . $tanda . ':' . time());
                Log::channel('akses')->error('akses:sinkron otomatis GAGAL', ['kode' => $kode, 'hasil' => $keluaran]);
            }
        } catch (Throwable $e) {
            self::tulis('gagal:' . $tanda . ':' . time());
            Log::channel('akses')->error('akses:sinkron otomatis GAGAL', ['error' => $e->getMessage()]);
        } finally {
            flock($kunci, LOCK_UN);
            fclose($kunci);
        }
    }

    /**
     * Berkas kunci yang dipakai bersama sinkron otomatis (web) dan perintah akses:sinkron manual, supaya
     * keduanya tidak pernah jalan bersamaan. Di shell setara dengan `flock storage/framework/akses-sinkron.tanda.lock`.
     *
     * @return resource|false
     */
    public static function bukaKunci()
    {
        return @fopen(self::berkasTanda() . '.lock', 'c');
    }

    /** Dipanggil juga oleh perintah akses:sinkron manual, supaya otomatis tidak mengulang. */
    public static function tulisTanda(): void
    {
        self::tulis(self::tandaSekarang());
    }

    private static function tandaSekarang(): string
    {
        $daftarPath = array_map('base_path', self::BERKAS_DEFINISI);
        $berkasProgram = glob(base_path(self::POLA_BERKAS_PROGRAM)) ?: [];
        sort($berkasProgram);
        $daftarPath = [...$daftarPath, ...$berkasProgram];

        $bagian = [];
        foreach ($daftarPath as $path) {
            clearstatcache(true, $path);
            // Path relatif (bukan basename): _kelompok.php ada di tiap folder kelompok.
            $bagian[] = substr($path, strlen(base_path()) + 1) . ':' . @filemtime($path) . '-' . @filesize($path);
        }

        return implode('|', $bagian);
    }

    private static function berkasTanda(): string
    {
        return storage_path('framework/akses-sinkron.tanda');
    }

    private static function tulis(string $isi): void
    {
        @file_put_contents(self::berkasTanda(), $isi, LOCK_EX);
    }
}
