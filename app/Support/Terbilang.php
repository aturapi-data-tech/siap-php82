<?php

namespace App\Support;

/**
 * Helper angka → kata (bahasa Indonesia) untuk baris "Terbilang" di kwitansi.
 *
 * Dibuat sebagai helper statis karena sebelumnya fungsi ini didefinisikan ULANG
 * di tiap cetakan sebagai fungsi GLOBAL berpenjaga function_exists() — tersebar
 * di 7 file dengan 3 nama (terbilang, terbilang_obat, terbilang_ri_obat). Nama
 * 'terbilang' saja dipakai oleh 4 file dengan DUA implementasi berbeda, sehingga
 * versi yang benar-benar terpakai bergantung cetakan mana yang dirender lebih
 * dulu dalam satu proses PHP — rapuh, dan menyulitkan perbaikan karena harus
 * diubah di banyak tempat.
 *
 * Perbedaan perilaku yang disatukan di sini:
 *   - Versi RI (ringkas & detail) mengembalikan string KOSONG untuk nilai
 *     >= 1 miliar, jadi tagihan semiliar ke atas mencetak "Rupiah" tanpa angka.
 *     Versi ini menangani sampai triliun.
 *   - Versi RI menyisipkan spasi di depan tiap penggal sehingga pemanggilnya
 *     harus trim() sendiri. Di sini hasilnya sudah rapi.
 *   - Nilai 0 dulu menghasilkan string kosong ("Rupiah" saja). Sekarang "nol".
 *   - Nilai negatif dulu memicu undefined array key. Sekarang diawali "minus".
 */
class Terbilang
{
    /** Angka dasar 0..11; di atas 11 disusun dari kombinasi (belas/puluh/ratus/…). */
    private const SATUAN = [
        '', 'satu', 'dua', 'tiga', 'empat', 'lima',
        'enam', 'tujuh', 'delapan', 'sembilan', 'sepuluh', 'sebelas',
    ];

    /**
     * Kata murni tanpa satuan mata uang, huruf kecil semua.
     * Contoh: 763500 → "tujuh ratus enam puluh tiga ribu lima ratus".
     */
    public static function kata(int $nilai): string
    {
        if ($nilai < 0) {
            return 'minus ' . self::kata(abs($nilai));
        }
        if ($nilai === 0) {
            return 'nol';
        }

        // Penggalan dirakit dengan spasi bebas lalu dirapikan sekali di sini,
        // supaya tiap cabang di susun() tak perlu mengurus spasi menggantung.
        return trim(preg_replace('/\s+/', ' ', self::susun($nilai)));
    }

    /**
     * Siap pakai untuk baris Terbilang di kwitansi — huruf awal kapital
     * dan berakhiran " Rupiah".
     * Contoh: 763500 → "Tujuh ratus enam puluh tiga ribu lima ratus Rupiah".
     */
    public static function rupiah(int $nilai): string
    {
        return ucfirst(self::kata($nilai)) . ' Rupiah';
    }

    /** Perakit rekursif. Selalu dipanggil dengan $nilai > 0. */
    private static function susun(int $nilai): string
    {
        if ($nilai < 12) {
            return self::SATUAN[$nilai];
        }
        if ($nilai < 20) {
            return self::susun($nilai - 10) . ' belas';
        }
        if ($nilai < 100) {
            return self::susun(intdiv($nilai, 10)) . ' puluh ' . self::susun($nilai % 10);
        }
        if ($nilai < 200) {
            return 'seratus ' . self::susun($nilai - 100);
        }
        if ($nilai < 1_000) {
            return self::susun(intdiv($nilai, 100)) . ' ratus ' . self::susun($nilai % 100);
        }
        if ($nilai < 2_000) {
            return 'seribu ' . self::susun($nilai - 1_000);
        }
        if ($nilai < 1_000_000) {
            return self::susun(intdiv($nilai, 1_000)) . ' ribu ' . self::susun($nilai % 1_000);
        }
        if ($nilai < 1_000_000_000) {
            return self::susun(intdiv($nilai, 1_000_000)) . ' juta ' . self::susun($nilai % 1_000_000);
        }
        if ($nilai < 1_000_000_000_000) {
            return self::susun(intdiv($nilai, 1_000_000_000)) . ' miliar ' . self::susun($nilai % 1_000_000_000);
        }
        return self::susun(intdiv($nilai, 1_000_000_000_000)) . ' triliun ' . self::susun($nilai % 1_000_000_000_000);
    }
}
