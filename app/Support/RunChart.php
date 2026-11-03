<?php

namespace App\Support;

/**
 * Run chart capaian indikator mutu — SVG murni (tanpa library JS), dipakai di layar dan PDF.
 * Garis capaian bulanan + garis target putus-putus; titik yang tidak mencapai target diberi
 * tanda silang (bukan hanya warna). Bulan tanpa kasus = garis terputus.
 *
 * Layar: warna lewat kelas token (ikut mode gelap). PDF (DomPDF): warna hex, dimuat sebagai
 * <img src="data:image/svg+xml;base64,…"> — DomPDF tidak merender <svg> inline.
 */
class RunChart
{
    private const LEBAR = 720;
    private const TINGGI = 230;
    private const KIRI = 44;
    private const KANAN = 14;
    private const ATAS = 14;
    private const BAWAH = 28;

    /**
     * @param  array<int, ?float>  $nilaiPerBulan  1..12 → capaian (null = tidak ada kasus)
     */
    public static function svg(array $nilaiPerBulan, array $definisi, bool $untukPdf = false): string
    {
        $target = (float) $definisi['target']['nilai'];
        $nilaiAda = array_values(array_filter($nilaiPerBulan, fn($nilai) => $nilai !== null));
        $batasAtas = self::batasAtas(max([$target, ...$nilaiAda]), $definisi['satuan']);

        $warna = $untukPdf
            ? ['garis' => 'stroke="#157547"', 'titik' => 'fill="#157547"', 'gagal' => 'stroke="#c64545"', 'target' => 'stroke="#c64545"',
               'teksTarget' => 'fill="#9c3232"', 'grid' => 'stroke="#e2e6e3"', 'teks' => 'fill="#5f6961"']
            : ['garis' => 'class="text-brand-green dark:text-brand-lime" stroke="currentColor"',
               'titik' => 'class="text-brand-green dark:text-brand-lime" fill="currentColor"',
               'gagal' => 'class="text-error-deep dark:text-red-400" stroke="currentColor"',
               'target' => 'class="text-error dark:text-red-400" stroke="currentColor"',
               'teksTarget' => 'class="text-error-deep dark:text-red-400" fill="currentColor"',
               'grid' => 'class="text-gray-200 dark:text-gray-700" stroke="currentColor"',
               'teks' => 'class="text-muted dark:text-gray-400" fill="currentColor"'];

        $lebarPlot = self::LEBAR - self::KIRI - self::KANAN;
        $tinggiPlot = self::TINGGI - self::ATAS - self::BAWAH;
        $x = fn(int $bulan) => self::KIRI + ($bulan - 0.5) * $lebarPlot / 12;
        $y = fn(float $nilai) => self::ATAS + $tinggiPlot - min($nilai, $batasAtas) / $batasAtas * $tinggiPlot;
        $f = fn(float $angka) => number_format($angka, 1, '.', '');

        $judul = htmlspecialchars('Run chart ' . $definisi['kode'] . ' ' . $definisi['judul'], ENT_QUOTES);
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . self::LEBAR . ' ' . self::TINGGI . '" width="100%"'
            . ($untukPdf ? ' height="' . self::TINGGI . '"' : '') . ' role="img" aria-label="' . $judul . '"><title>' . $judul . '</title>';

        // Grid & label sumbu Y (5 garis)
        for ($langkah = 0; $langkah <= 4; $langkah++) {
            $nilai = $batasAtas * $langkah / 4;
            $svg .= '<line x1="' . self::KIRI . '" x2="' . (self::LEBAR - self::KANAN) . '" y1="' . $f($y($nilai)) . '" y2="' . $f($y($nilai)) . '" ' . $warna['grid'] . ' stroke-width="1"/>';
            $svg .= '<text x="' . (self::KIRI - 6) . '" y="' . $f($y($nilai) + 3.5) . '" text-anchor="end" font-size="10" font-family="sans-serif" ' . $warna['teks'] . '>' . IndikatorMutu::angka($nilai, $batasAtas < 10 ? 1 : 0) . '</text>';
        }
        // Label bulan
        foreach (IndikatorMutu::NAMA_BULAN as $bulan => $nama) {
            $svg .= '<text x="' . $f($x($bulan)) . '" y="' . (self::TINGGI - 9) . '" text-anchor="middle" font-size="10" font-family="sans-serif" ' . $warna['teks'] . '>' . $nama . '</text>';
        }
        // Garis target
        $svg .= '<line x1="' . self::KIRI . '" x2="' . (self::LEBAR - self::KANAN) . '" y1="' . $f($y($target)) . '" y2="' . $f($y($target)) . '" ' . $warna['target'] . ' stroke-width="1.5" stroke-dasharray="6 4"/>';
        $svg .= '<text x="' . (self::LEBAR - self::KANAN - 2) . '" y="' . $f($y($target) - 4) . '" text-anchor="end" font-size="10" font-weight="bold" font-family="sans-serif" ' . $warna['teksTarget'] . '>Target ' . htmlspecialchars(IndikatorMutu::labelTarget($definisi)) . '</text>';

        // Garis capaian — terputus di bulan tanpa kasus
        $segmen = [];
        $titikSegmen = [];
        foreach (range(1, 12) as $bulan) {
            $nilai = $nilaiPerBulan[$bulan] ?? null;
            if ($nilai === null) {
                $segmen[] = $titikSegmen;
                $titikSegmen = [];
                continue;
            }
            $titikSegmen[] = $f($x($bulan)) . ',' . $f($y($nilai));
        }
        $segmen[] = $titikSegmen;
        foreach ($segmen as $titik) {
            if (count($titik) > 1) {
                $svg .= '<polyline points="' . implode(' ', $titik) . '" fill="none" ' . $warna['garis'] . ' stroke-width="2.5"/>';
            }
        }
        foreach (range(1, 12) as $bulan) {
            $nilai = $nilaiPerBulan[$bulan] ?? null;
            if ($nilai === null) {
                continue;
            }
            $cx = $x($bulan);
            $cy = $y($nilai);
            $svg .= '<circle cx="' . $f($cx) . '" cy="' . $f($cy) . '" r="4" ' . $warna['titik'] . '/>';
            if (IndikatorMutu::tercapai($nilai, $definisi) === false) {
                $svg .= '<path d="M' . $f($cx - 6) . ' ' . $f($cy - 6) . 'L' . $f($cx + 6) . ' ' . $f($cy + 6) . 'M' . $f($cx + 6) . ' ' . $f($cy - 6) . 'L' . $f($cx - 6) . ' ' . $f($cy + 6) . '" ' . $warna['gagal'] . ' stroke-width="2"/>';
            }
            $svg .= '<text x="' . $f($cx) . '" y="' . $f($cy - 9) . '" text-anchor="middle" font-size="9.5" font-family="sans-serif" ' . $warna['teks'] . '>' . IndikatorMutu::angka($nilai, 1) . '</text>';
        }

        return $svg . '</svg>';
    }

    /** SVG sebagai data URI untuk <img> di DomPDF. */
    public static function dataUri(array $nilaiPerBulan, array $definisi): string
    {
        return 'data:image/svg+xml;base64,' . base64_encode(self::svg($nilaiPerBulan, $definisi, true));
    }

    /** Batas atas sumbu Y yang "bulat": persen ≥ 50 → 100, selain itu 1/2/5 × 10ⁿ di atas nilai terbesar. */
    private static function batasAtas(float $maksimum, string $satuan): float
    {
        if ($maksimum >= 50 || $satuan === '') {
            return 100;
        }
        $maksimum = max($maksimum * 1.2, 1);
        $pangkat = 10 ** floor(log10($maksimum));
        foreach ([1, 2, 5, 10] as $kelipatan) {
            if ($kelipatan * $pangkat >= $maksimum) {
                return $kelipatan * $pangkat;
            }
        }

        return 10 * $pangkat;
    }
}
