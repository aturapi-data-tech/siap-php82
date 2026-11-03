<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Protection;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Form Excel per BAGIAN untuk slip gaji — pengganti sheet-sheet terpisah di workbook gaji lama
 * (lembur, JaMed, angsuran, peg/BON, BPJS, …) yang dulu dirangkum sheet "gaji" lewat SUMIF nama.
 *
 * Alur: keuangan EKSPOR form satu bagian (berisi daftar slip periode + nilai saat ini) → dikirim ke
 * bagian terkait → bagian mengisi kolom KUNING → keuangan IMPOR → slip Draft diperbarui.
 *
 * Aturan impor:
 *  - Baris dicocokkan lewat kolom "ID Karyawan" (bukan nama — nama bisa beda ejaan).
 *  - Form = keadaan komponen bulan itu: nilai di kolom kuning MENGGANTI nilai komponen di slip.
 *    Kosong = 0 (komponen dihapus), kecuali Hari Kerja & Kenaikan Gaji: kosong = tidak diubah.
 *  - Baris yang nilainya sama dengan slip dilewati (tidak menandai apa pun).
 *  - Slip Final dilewati — buka ke Draft dulu bila memang perlu diubah.
 *  - Komponen rutin dari parameter (Koperasi, BPJS) ditulis sebagai "ditimpa" → aman saat generate ulang.
 *  - Sheet tersembunyi "_form" menyimpan bagian & periode; impor menolak form bagian/periode lain.
 */
class GajiFormBagian
{
    /** Upah lembur ala workbook lama: jam ÷ 7 jam/hari ÷ 22 hari × dasar upah × 150 %. */
    public const LEMBUR_JAM_PER_HARI = 7;
    public const LEMBUR_HARI_ACUAN   = 22;
    public const LEMBUR_FAKTOR       = 1.5;

    /** Komponen yang menjadi dasar upah lembur. */
    private const KODE_DASAR_LEMBUR = ['POKOK', 'JASA_MEDIK', 'MAKAN', 'TRANSPORT'];

    private const JM_INAP = 'Rawat Inap';
    private const JM_RJ   = 'Rawat Jalan / UGD';

    private const BARIS_PETUNJUK = 4;
    private const BARIS_HEADER   = 5;
    private const BARIS_CONTOH   = 6; // contoh pengisian — tidak diimpor
    private const BARIS_DATA     = 7;
    private const META_SHEET   = '_form';

    /** Kolom tetap di depan setiap form. */
    private const KOLOM_IDENTITAS = [
        ['key' => 'emp_id', 'label' => 'ID Karyawan', 'lebar' => 14],
        ['key' => 'emp_name', 'label' => 'Nama', 'lebar' => 32],
        ['key' => 'unit_name', 'label' => 'Unit', 'lebar' => 20],
        ['key' => 'status', 'label' => 'Status Slip', 'lebar' => 11],
    ];

    /**
     * Definisi bagian. kolom: tipe rupiah|angka|teks; isi = true → kolom kuning (diisi bagian).
     * @return array<string, array{judul: string, petugas: string, keterangan: string, kolom: array}>
     */
    public static function daftar(): array
    {
        return [
            'hari_kerja' => [
                'judul' => 'Hari Kerja', 'petugas' => 'Kepegawaian',
                'keterangan' => 'Hari kerja slip (pengali tunjangan makan & transport). Kosong = tidak diubah.',
                'kolom' => [
                    ['key' => 'hadir', 'label' => 'Hadir (absensi)', 'tipe' => 'angka', 'contoh' => 22, 'petunjuk' => 'Acuan dari absensi'],
                    ['key' => 'hari_kerja', 'label' => 'Hari Kerja', 'tipe' => 'angka', 'isi' => true, 'contoh' => 20, 'petunjuk' => 'Bilangan bulat 0–31. Kosong = tidak diubah'],
                ],
            ],
            'lembur' => [
                'judul' => 'Lembur', 'petugas' => 'Kepegawaian',
                'keterangan' => 'Isi jam lembur; upah dihitung otomatis (jam ÷ 7 ÷ 22 × dasar × 150%) dan boleh ditimpa.',
                'kolom' => [
                    ['key' => 'dasar', 'label' => 'Dasar Upah', 'tipe' => 'rupiah', 'contoh' => 3000000, 'petunjuk' => 'Pokok + JM + makan + transport'],
                    ['key' => 'jam', 'label' => 'Jam Lembur', 'tipe' => 'angka', 'isi' => true, 'contoh' => 14, 'petunjuk' => 'Total jam sebulan, mis. 14 atau 3,5'],
                    ['key' => 'upah', 'label' => 'Upah Lembur', 'tipe' => 'rupiah', 'isi' => true, 'contoh' => 'rumus', 'petunjuk' => 'Terhitung otomatis dari jam; boleh ditimpa'],
                ],
            ],
            'bon' => [
                'judul' => 'Bon / Kasbon', 'petugas' => 'Kasir / Keuangan',
                'keterangan' => 'Total bon yang telah diambil bulan ini — dipotong dari gaji.',
                'kolom' => [
                    ['key' => 'kasbon', 'label' => 'Bon (telah diambil)', 'tipe' => 'rupiah', 'isi' => true, 'contoh' => 250000, 'petunjuk' => 'Total bon sebulan, angka saja'],
                ],
            ],
            'jasa_medik' => [
                'judul' => 'Jasa Medik', 'petugas' => 'Bagian Jasa Medik',
                'keterangan' => 'Pembagian jasa medik per karyawan, dipisah sumbernya.',
                'kolom' => [
                    ['key' => 'jm_inap', 'label' => 'JM ' . self::JM_INAP, 'tipe' => 'rupiah', 'isi' => true, 'contoh' => 750000, 'petunjuk' => 'Angka saja, tanpa titik / Rp'],
                    ['key' => 'jm_rj', 'label' => 'JM ' . self::JM_RJ, 'tipe' => 'rupiah', 'isi' => true, 'contoh' => 300000, 'petunjuk' => 'Angka saja'],
                    ['key' => 'jm_lain', 'label' => 'JM Lain', 'tipe' => 'rupiah', 'isi' => true, 'contoh' => null, 'petunjuk' => 'Kosongkan bila tidak ada'],
                ],
            ],
            'kenaikan_gaji' => [
                'judul' => 'Kenaikan Gaji', 'petugas' => 'Kepegawaian / Direksi',
                'keterangan' => 'Isi nilai BARU saja (kosong = tetap). Mengubah parameter gaji dan slip Draft bulan ini.',
                'kolom' => [
                    ['key' => 'pokok_lama', 'label' => 'Gaji Pokok Saat Ini', 'tipe' => 'rupiah', 'contoh' => 2262738, 'petunjuk' => 'Acuan'],
                    ['key' => 'pokok_baru', 'label' => 'Gaji Pokok Baru', 'tipe' => 'rupiah', 'isi' => true, 'contoh' => 2400000, 'petunjuk' => 'Isi hanya bila naik. Kosong = tetap'],
                    ['key' => 'struktural_lama', 'label' => 'Tunj. Struktural Saat Ini', 'tipe' => 'rupiah', 'contoh' => 500000, 'petunjuk' => 'Acuan'],
                    ['key' => 'struktural_baru', 'label' => 'Tunj. Struktural Baru', 'tipe' => 'rupiah', 'isi' => true, 'contoh' => null, 'petunjuk' => 'Kosong = tetap'],
                    ['key' => 'profesi_lama', 'label' => 'Tunj. Profesi Saat Ini', 'tipe' => 'rupiah', 'contoh' => 0, 'petunjuk' => 'Acuan'],
                    ['key' => 'profesi_baru', 'label' => 'Tunj. Profesi Baru', 'tipe' => 'rupiah', 'isi' => true, 'contoh' => 150000, 'petunjuk' => 'Kosong = tetap'],
                ],
            ],
            'koperasi' => [
                'judul' => 'Koperasi', 'petugas' => 'Koperasi',
                'keterangan' => 'Iuran koperasi bulan ini (menimpa iuran rutin parameter) dan angsuran pinjaman koperasi. "Angsuran Lain" = angsuran gabungan lama di slip; hapus di rincian slip bila sudah dirinci di sini.',
                'kolom' => [
                    ['key' => 'angs_lain', 'label' => 'Angsuran Lain (belum dirinci)', 'tipe' => 'rupiah', 'contoh' => 0, 'petunjuk' => 'Acuan: angsuran gabungan lama'],
                    ['key' => 'iuran', 'label' => 'Iuran Koperasi', 'tipe' => 'rupiah', 'isi' => true, 'contoh' => 100000, 'petunjuk' => 'Iuran bulan ini (menimpa iuran rutin)'],
                    ['key' => 'angsuran', 'label' => 'Angsuran Koperasi', 'tipe' => 'rupiah', 'isi' => true, 'contoh' => 448000, 'petunjuk' => 'Angsuran pinjaman koperasi'],
                ],
            ],
            'bank' => [
                'judul' => 'Pinjaman Bank', 'petugas' => 'Keuangan',
                'keterangan' => 'Angsuran pinjaman bank yang dipotong dari gaji (maks. 2 bank per karyawan). "Angsuran Lain" = angsuran gabungan lama di slip; hapus di rincian slip bila sudah dirinci di sini.',
                'kolom' => [
                    ['key' => 'angs_lain', 'label' => 'Angsuran Lain (belum dirinci)', 'tipe' => 'rupiah', 'contoh' => 0, 'petunjuk' => 'Acuan: angsuran gabungan lama'],
                    ['key' => 'bank1', 'label' => 'Bank 1', 'tipe' => 'teks', 'isi' => true, 'contoh' => 'BRI', 'petunjuk' => 'Nama bank'],
                    ['key' => 'angs1', 'label' => 'Angsuran Bank 1', 'tipe' => 'rupiah', 'isi' => true, 'contoh' => 1200000, 'petunjuk' => 'Angka saja'],
                    ['key' => 'bank2', 'label' => 'Bank 2', 'tipe' => 'teks', 'isi' => true, 'contoh' => 'BSI', 'petunjuk' => 'Kosongkan bila hanya 1 bank'],
                    ['key' => 'angs2', 'label' => 'Angsuran Bank 2', 'tipe' => 'rupiah', 'isi' => true, 'contoh' => 650000, 'petunjuk' => 'Angka saja'],
                ],
            ],
            'bpjs' => [
                'judul' => 'BPJS', 'petugas' => 'Kepegawaian',
                'keterangan' => 'Iuran BPJS bagian karyawan bulan ini (menimpa potongan rutin parameter).',
                'kolom' => [
                    ['key' => 'bpjs', 'label' => 'BPJS Kesehatan', 'tipe' => 'rupiah', 'isi' => true, 'contoh' => 45000, 'petunjuk' => 'Iuran bagian karyawan'],
                    ['key' => 'bpjs_tk', 'label' => 'BPJS Ketenagakerjaan', 'tipe' => 'rupiah', 'isi' => true, 'contoh' => 30000, 'petunjuk' => 'Iuran bagian karyawan'],
                ],
            ],
        ];
    }

    public static function ada(string $bagian): bool
    {
        return isset(self::daftar()[$bagian]);
    }

    public static function namaBerkas(string $bagian, string $periode, ?string $unitId): string
    {
        return 'form-gaji-' . str_replace('_', '-', $bagian) . '-' . $periode . ($unitId ? '-' . mb_strtolower($unitId) : '') . '.xlsx';
    }

    // ─── EKSPOR ───────────────────────────────────────────────────────────────

    /** Null bila tidak ada baris (belum generate / belum ada parameter). */
    public static function ekspor(string $bagian, string $periode, ?string $unitId): ?Spreadsheet
    {
        $definisi = self::daftar()[$bagian];
        $baris = self::barisData($bagian, $periode, $unitId);
        if ($baris->isEmpty()) {
            return null;
        }

        $kolomList = [...self::KOLOM_IDENTITAS, ...$definisi['kolom']];
        $huruf = [];
        foreach ($kolomList as $i => $kolom) {
            $huruf[$kolom['key']] = Coordinate::stringFromColumnIndex($i + 1);
        }
        $kolomAkhir = end($huruf);

        $book = new Spreadsheet();
        $book->getProperties()->setCreator(Identitas::namaPendek())->setTitle('Form ' . $definisi['judul']);
        $sheet = $book->getActiveSheet();
        $sheet->setTitle(mb_substr(str_replace('/', '-', $definisi['judul']), 0, 31));

        $bulan = GajiKaryawan::awalPeriode($periode);
        $sheet->setCellValue('A1', 'FORM ' . mb_strtoupper($definisi['judul']) . ' — ' . Identitas::namaPendek());
        $sheet->setCellValue('A2', 'Bulan kerja ' . Format::bulan($bulan) . ' · diisi oleh ' . $definisi['petugas']
            . ($unitId ? ' · unit ' . (DB::table('siapmst_unit_kerjas')->where('unit_id', $unitId)->value('unit_name') ?? $unitId) : ''));
        $sheet->setCellValue('A3', 'Isi hanya kolom KUNING (lihat baris CONTOH biru di bawah judul kolom). ' . $definisi['keterangan'] . ' Jangan ubah kolom ID Karyawan & jangan menambah baris.');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $sheet->getStyle('A2:A3')->getFont()->setItalic(true)->getColor()->setRGB('5F6961');

        // Header
        foreach ($kolomList as $kolom) {
            $sel = $huruf[$kolom['key']] . self::BARIS_HEADER;
            $sheet->setCellValue($sel, $kolom['label']);
            $sheet->getColumnDimension($huruf[$kolom['key']])->setWidth($kolom['lebar'] ?? ($kolom['tipe'] === 'teks' ? 16 : 18));
        }
        $rentangHeader = 'A' . self::BARIS_HEADER . ':' . $kolomAkhir . self::BARIS_HEADER;
        $sheet->getStyle($rentangHeader)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle($rentangHeader)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('157547');
        $sheet->getStyle($rentangHeader)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER)->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getRowDimension(self::BARIS_HEADER)->setRowHeight(32);

        self::isiPetunjukDanContoh($sheet, $bagian, $kolomList, $huruf, $kolomAkhir);

        // Data
        $nomorBaris = self::BARIS_DATA;
        foreach ($baris as $data) {
            foreach ($kolomList as $kolom) {
                $sel = $huruf[$kolom['key']] . $nomorBaris;
                $nilai = $data[$kolom['key']] ?? null;
                if ($kolom['key'] === 'emp_id' || ($kolom['tipe'] ?? '') === 'teks') {
                    $sheet->setCellValueExplicit($sel, (string) $nilai, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                } elseif ($nilai !== null && $nilai !== '' && !(!empty($kolom['isi']) && (float) $nilai == 0)) { // isian 0 → sel kosong
                    $sheet->setCellValue($sel, $nilai);
                }
            }
            if ($bagian === 'lembur') {
                self::isiRumusLembur($sheet, $huruf, $nomorBaris, $data);
            }
            $nomorBaris++;
        }
        $barisAkhir = $nomorBaris - 1;

        // Gaya kolom
        foreach ($kolomList as $kolom) {
            $rentang = $huruf[$kolom['key']] . self::BARIS_DATA . ':' . $huruf[$kolom['key']] . $barisAkhir;
            $tipe = $kolom['tipe'] ?? 'teks';
            if ($tipe === 'rupiah') {
                $sheet->getStyle($rentang)->getNumberFormat()->setFormatCode('#,##0');
            } elseif ($tipe === 'angka') {
                $sheet->getStyle($rentang)->getNumberFormat()->setFormatCode('0.##');
                $sheet->getStyle($rentang)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            }
            if (!empty($kolom['isi'])) {
                $sheet->getStyle($rentang)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFF4C2');
                $sheet->getStyle($rentang)->getProtection()->setLocked(Protection::PROTECTION_UNPROTECTED);
                if ($tipe !== 'teks') {
                    $validasi = $sheet->getCell($huruf[$kolom['key']] . self::BARIS_DATA)->getDataValidation();
                    $validasi->setType(DataValidation::TYPE_DECIMAL)->setOperator(DataValidation::OPERATOR_BETWEEN)
                        ->setFormula1('0')->setFormula2($tipe === 'angka' && $kolom['key'] === 'hari_kerja' ? '31' : '999999999')
                        ->setAllowBlank(true)->setShowErrorMessage(true)
                        ->setErrorTitle('Nilai tidak valid')->setError('Isi angka positif' . ($kolom['key'] === 'hari_kerja' ? ' (0–31)' : '') . ', tanpa titik/Rp.');
                    $validasi->setSqref($rentang);
                }
            } else {
                $sheet->getStyle($rentang)->getFont()->getColor()->setRGB('3C463F');
            }
        }
        $rentangTabel = 'A' . self::BARIS_HEADER . ':' . $kolomAkhir . $barisAkhir;
        $sheet->getStyle($rentangTabel)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('C9D0CB');

        // Baris total untuk kolom rupiah
        $barisTotal = $barisAkhir + 1;
        $sheet->setCellValue('B' . $barisTotal, 'TOTAL (' . $baris->count() . ' karyawan)');
        foreach ($kolomList as $kolom) {
            if (($kolom['tipe'] ?? '') === 'rupiah') {
                $h = $huruf[$kolom['key']];
                $sheet->setCellValue($h . $barisTotal, "=SUM({$h}" . self::BARIS_DATA . ":{$h}{$barisAkhir})");
                $sheet->getStyle($h . $barisTotal)->getNumberFormat()->setFormatCode('#,##0');
            }
        }
        $sheet->getStyle('A' . $barisTotal . ':' . $kolomAkhir . $barisTotal)->getFont()->setBold(true);

        $sheet->freezePane('C' . self::BARIS_DATA);
        // Proteksi bersandi: hanya kolom kuning yang bisa diisi; baris/kolom tidak bisa disisip/dihapus.
        $sheet->getProtection()->setSheet(true)->setPassword(self::sandi())
            ->setFormatColumns(false)->setFormatRows(false)->setSort(true)->setAutoFilter(true)
            ->setInsertRows(true)->setInsertColumns(true)->setDeleteRows(true)->setDeleteColumns(true);
        $sheet->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE)->setFitToWidth(1)->setFitToHeight(0);

        $meta = $book->createSheet();
        $meta->setTitle(self::META_SHEET);
        $meta->fromArray([['bagian', $bagian], ['periode', $periode], ['unit', $unitId ?? ''], ['dibuat', now()->format(Format::WAKTU)]]);
        $meta->setSheetState(Worksheet::SHEETSTATE_VERYHIDDEN);
        $book->setActiveSheetIndex(0);
        // Struktur workbook dikunci: sheet penanda "_form" tidak bisa dimunculkan / dihapus / diganti.
        $book->getSecurity()->setLockStructure(true)->setWorkbookPassword(self::sandi());

        return $book;
    }

    /** Baris 4 = petunjuk per kolom isian; baris 6 = CONTOH pengisian (dilewati saat impor). */
    private static function isiPetunjukDanContoh(Worksheet $sheet, string $bagian, array $kolomList, array $huruf, string $kolomAkhir): void
    {
        $p = self::BARIS_PETUNJUK;
        $c = self::BARIS_CONTOH;
        $sheet->setCellValue("A{$p}", 'Cara isi ↓');
        $sheet->setCellValue("A{$c}", 'CONTOH');
        $sheet->setCellValue("B{$c}", 'Contoh pengisian — baris ini tidak diimpor');
        $sheet->setCellValue("C{$c}", '(unit)');
        $sheet->setCellValue("D{$c}", 'Draft');

        foreach ($kolomList as $kolom) {
            if (!array_key_exists('contoh', $kolom)) {
                continue;
            }
            $h = $huruf[$kolom['key']];
            $sheet->setCellValue("{$h}{$p}", $kolom['petunjuk'] ?? '');
            if ($kolom['contoh'] === 'rumus') { // upah lembur contoh = rumus yang sama dengan baris data
                $sheet->setCellValue("{$h}{$c}", sprintf('=ROUND(%s/%d/%d*%s*%s,0)', $huruf['jam'] . $c,
                    self::LEMBUR_JAM_PER_HARI, self::LEMBUR_HARI_ACUAN, $huruf['dasar'] . $c, self::LEMBUR_FAKTOR));
            } elseif ($kolom['contoh'] !== null) {
                $sheet->setCellValue("{$h}{$c}", $kolom['contoh']);
            }
            $format = match ($kolom['tipe']) { 'rupiah' => '#,##0', 'angka' => '0.##', default => '@' };
            $sheet->getStyle("{$h}{$c}")->getNumberFormat()->setFormatCode($format);
            if ($kolom['tipe'] === 'angka') {
                $sheet->getStyle("{$h}{$c}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            }
        }

        $sheet->getStyle("A{$p}:{$kolomAkhir}{$p}")->getFont()->setItalic(true)->setSize(9)->getColor()->setRGB('8A6A0E');
        $sheet->getStyle("A{$p}:{$kolomAkhir}{$p}")->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_BOTTOM);
        $sheet->getRowDimension($p)->setRowHeight(36);

        $rentangContoh = "A{$c}:{$kolomAkhir}{$c}";
        $sheet->getStyle($rentangContoh)->getFont()->setItalic(true)->getColor()->setRGB('205D7D');
        $sheet->getStyle($rentangContoh)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E3EFF5');
        $sheet->getStyle($rentangContoh)->getBorders()->getBottom()->setBorderStyle(Border::BORDER_MEDIUM)->getColor()->setRGB('205D7D');
        $sheet->getStyle("A{$c}")->getFont()->setBold(true);
    }

    /** Upah lembur pakai rumus Excel (ikut berubah saat jam diisi) — kecuali nilai di slip ditimpa manual. */
    private static function isiRumusLembur(Worksheet $sheet, array $huruf, int $baris, array $data): void
    {
        $jam = (float) ($data['jam'] ?? 0);
        $upah = (float) ($data['upah'] ?? 0);
        if ($upah > 0 && abs($upah - self::hitungUpahLembur($jam, (float) $data['dasar'])) > 1) {
            return; // nilai lama bukan hasil rumus → biarkan angka apa adanya
        }
        $j = $huruf['jam'] . $baris;
        $d = $huruf['dasar'] . $baris;
        $sheet->setCellValue($huruf['upah'] . $baris, sprintf('=IF(N(%s)=0,0,ROUND(%s/%d/%d*%s*%s,0))',
            $j, $j, self::LEMBUR_JAM_PER_HARI, self::LEMBUR_HARI_ACUAN, $d, self::LEMBUR_FAKTOR));
    }

    /** Sandi proteksi form (config gaji_form.sandi / env GAJI_FORM_SANDI) — hanya keuangan yang tahu. */
    private static function sandi(): string
    {
        return (string) (config('app.gaji_form_sandi') ?: 'siap-gaji');
    }

    public static function hitungUpahLembur(float $jam, float $dasar): float
    {
        return $jam <= 0 ? 0.0 : round($jam / self::LEMBUR_JAM_PER_HARI / self::LEMBUR_HARI_ACUAN * $dasar * self::LEMBUR_FAKTOR);
    }

    /**
     * Baris form: identitas + nilai saat ini per kolom.
     * @return Collection<int, array<string, mixed>>
     */
    private static function barisData(string $bagian, string $periode, ?string $unitId): Collection
    {
        if ($bagian === 'kenaikan_gaji') {
            return DB::table('siapmst_gaji_karyawans as gk')
                ->join('siapmst_employers as em', 'em.emp_id', '=', 'gk.emp_id')
                ->leftJoin('siapmst_unit_kerjas as un', 'un.unit_id', '=', 'em.emp_unit_id')
                ->leftJoin('siaptxn_gaji_hdrs as gh', fn($join) => $join->on('gh.emp_id', '=', 'gk.emp_id')->where('gh.periode', '=', $periode))
                ->where('em.active_status', '1')
                ->when($unitId, fn($query) => $query->where('em.emp_unit_id', $unitId))
                ->orderBy('un.unit_name')->orderBy('em.emp_name')
                ->get(['gk.emp_id', 'em.emp_name', 'un.unit_name', 'gh.gaji_status', 'gk.gaji_pokok', 'gk.tunjangan_struktural', 'gk.tunjangan_profesi'])
                ->map(fn($row) => [
                    'emp_id' => $row->emp_id, 'emp_name' => $row->emp_name, 'unit_name' => $row->unit_name,
                    'status' => $row->gaji_status ? (GajiKaryawan::STATUS[$row->gaji_status] ?? $row->gaji_status) : 'Belum ada slip',
                    'pokok_lama' => (float) $row->gaji_pokok, 'struktural_lama' => (float) $row->tunjangan_struktural, 'profesi_lama' => (float) $row->tunjangan_profesi,
                ]);
        }

        $slipList = self::slipPeriode($periode, $unitId);
        $detail = DB::table('siaptxn_gaji_dtls')->whereIn('gaji_id', $slipList->pluck('gaji_id')->all() ?: [0])
            ->orderBy('dtl_id')->get()->groupBy('gaji_id');
        $hadir = $bagian === 'hari_kerja' ? GajiKaryawan::hariHadir($slipList->pluck('emp_id')->all(), $periode) : [];

        return $slipList->map(fn($slip) => [
            'emp_id' => $slip->emp_id, 'emp_name' => $slip->emp_name, 'unit_name' => $slip->unit_name,
            'status' => GajiKaryawan::STATUS[$slip->gaji_status] ?? $slip->gaji_status,
            ...($bagian === 'hari_kerja' ? ['hadir' => $hadir[$slip->emp_id] ?? 0] : []),
            ...self::nilaiSaatIni($bagian, $slip, $detail[$slip->gaji_id] ?? collect()),
        ]);
    }

    /** @return Collection<int, object> slip periode (+ filter unit), urut unit lalu nama. */
    private static function slipPeriode(string $periode, ?string $unitId): Collection
    {
        return DB::table('siaptxn_gaji_hdrs as gh')
            ->join('siapmst_employers as em', 'em.emp_id', '=', 'gh.emp_id')
            ->leftJoin('siapmst_unit_kerjas as un', 'un.unit_id', '=', 'gh.unit_id')
            ->where('gh.periode', $periode)
            ->when($unitId, fn($query) => $query->where('gh.unit_id', $unitId))
            ->orderBy('un.unit_name')->orderBy('em.emp_name')
            ->get(['gh.gaji_id', 'gh.emp_id', 'gh.hari_hadir', 'gh.gaji_status', 'em.emp_name', 'un.unit_name']);
    }

    /** Nilai komponen bagian di slip (rupiah positif) — dipakai ekspor & pembanding saat impor. */
    private static function nilaiSaatIni(string $bagian, object $slip, Collection $detail): array
    {
        $jumlah = fn(string $kode, ?callable $saring = null) => (float) abs($detail->where('kode', $kode)
            ->filter($saring ?? fn() => true)->sum(fn($row) => (float) $row->nilai));

        switch ($bagian) {
            case 'hari_kerja':
                return ['hari_kerja' => (int) $slip->hari_hadir];
            case 'lembur':
                $barisLembur = $detail->where('kode', 'LEMBUR');
                return [
                    'dasar' => (float) $detail->whereIn('kode', self::KODE_DASAR_LEMBUR)->sum(fn($row) => (float) $row->nilai),
                    'jam'   => (float) $barisLembur->sum(fn($row) => (float) $row->jumlah),
                    'upah'  => $jumlah('LEMBUR'),
                ];
            case 'bon':
                return ['kasbon' => $jumlah('KASBON')];
            case 'jasa_medik':
                return [
                    // str_starts_with: baris dari menu Pembagian JM berketerangan "Rawat Inap (3 poin)".
                    'jm_inap' => $jumlah('JASA_MEDIK', fn($row) => str_starts_with((string) $row->keterangan, self::JM_INAP)),
                    'jm_rj'   => $jumlah('JASA_MEDIK', fn($row) => str_starts_with((string) $row->keterangan, self::JM_RJ)),
                    'jm_lain' => $jumlah('JASA_MEDIK', fn($row) => !str_starts_with((string) $row->keterangan, self::JM_INAP)
                        && !str_starts_with((string) $row->keterangan, self::JM_RJ)),
                ];
            case 'koperasi':
                return ['angs_lain' => $jumlah('ANGSURAN'), 'iuran' => $jumlah('KOPERASI'), 'angsuran' => $jumlah('ANGS_KOP')];
            case 'bank':
                $barisBank = $detail->where('kode', 'ANGS_BANK')->values();
                return [
                    'angs_lain' => $jumlah('ANGSURAN'),
                    'bank1' => (string) ($barisBank[0]->keterangan ?? ''),
                    'angs1' => abs((float) ($barisBank[0]->nilai ?? 0)),
                    'bank2' => (string) ($barisBank[1]->keterangan ?? ''),
                    'angs2' => (float) abs($barisBank->slice(1)->sum(fn($row) => (float) $row->nilai)),
                ];
            case 'bpjs':
                return ['bpjs' => $jumlah('BPJS'), 'bpjs_tk' => $jumlah('BPJS_TK')];
        }

        return [];
    }

    // ─── IMPOR ────────────────────────────────────────────────────────────────

    /**
     * @return array{ok: bool, pesan: string, diperbarui: int, sama: int, final: array<int, string>, tidakDikenal: array<int, string>, galat: array<int, string>}
     */
    public static function impor(string $bagian, string $path, string $periode, ?int $userId): array
    {
        $hasil = ['ok' => false, 'pesan' => '', 'diperbarui' => 0, 'sama' => 0, 'final' => [], 'tidakDikenal' => [], 'galat' => []];

        try {
            $book = IOFactory::createReader('Xlsx')->load($path);
        } catch (\Throwable) {
            return [...$hasil, 'pesan' => 'Berkas tidak bisa dibaca. Pastikan format .xlsx dari tombol Ekspor.'];
        }

        $meta = $book->getSheetByName(self::META_SHEET);
        $metaBagian = $meta ? (string) $meta->getCell('B1')->getValue() : '';
        $metaPeriode = $meta ? (string) $meta->getCell('B2')->getValue() : '';
        if ($metaBagian !== $bagian) {
            $nama = self::daftar()[$metaBagian]['judul'] ?? null;
            return [...$hasil, 'pesan' => $nama ? "Ini form {$nama}, bukan " . self::daftar()[$bagian]['judul'] . '.' : 'Bukan form hasil Ekspor modul gaji (penanda form tidak ditemukan).'];
        }
        if ($metaPeriode !== $periode) {
            return [...$hasil, 'pesan' => 'Form ini untuk bulan ' . Format::bulan(GajiKaryawan::awalPeriode($metaPeriode)) . ', sedangkan bulan terpilih ' . Format::bulan(GajiKaryawan::awalPeriode($periode)) . '. Ganti bulan dulu.'];
        }

        $sheet = $book->getSheet(0);
        $definisi = self::daftar()[$bagian];
        $kolomList = [...self::KOLOM_IDENTITAS, ...$definisi['kolom']];

        // Header harus sama dengan ekspor (kolom tidak digeser / dihapus).
        foreach ($kolomList as $i => $kolom) {
            $sel = Coordinate::stringFromColumnIndex($i + 1) . self::BARIS_HEADER;
            if (trim((string) $sheet->getCell($sel)->getValue()) !== $kolom['label']) {
                return [...$hasil, 'pesan' => "Susunan kolom berubah (sel {$sel} seharusnya \"{$kolom['label']}\"). Ekspor ulang form, jangan sisip/hapus kolom."];
            }
        }

        $isian = [];
        $ganda = [];
        $barisAkhir = $sheet->getHighestDataRow();
        for ($baris = self::BARIS_DATA; $baris <= $barisAkhir; $baris++) {
            $empId = trim((string) $sheet->getCell('A' . $baris)->getValue());
            if ($empId === '' || mb_strtoupper($empId) === 'CONTOH') {
                continue;
            }
            $nilai = [];
            foreach ($definisi['kolom'] as $i => $kolom) {
                if (empty($kolom['isi'])) {
                    continue;
                }
                $sel = Coordinate::stringFromColumnIndex(count(self::KOLOM_IDENTITAS) + $i + 1) . $baris;
                try {
                    $mentah = $sheet->getCell($sel)->getCalculatedValue();
                } catch (\Throwable) {
                    $hasil['galat'][] = "Baris {$baris}: rumus di {$sel} galat.";
                    continue 2;
                }
                if ($kolom['tipe'] === 'teks') {
                    $nilai[$kolom['key']] = mb_substr(trim((string) $mentah), 0, 60);
                    continue;
                }
                $angka = self::angka($mentah);
                if ($angka === false || $angka < 0) {
                    $hasil['galat'][] = "Baris {$baris} ({$empId}): {$kolom['label']} \"{$mentah}\" bukan angka positif.";
                    continue 2;
                }
                $nilai[$kolom['key']] = $angka;
            }
            if (isset($isian[$empId])) {
                $hasil['galat'][] = "Baris {$baris}: ID {$empId} ganda (sudah ada di baris {$isian[$empId]['baris']}) — keduanya dilewati.";
                $ganda[$empId] = true;
                continue;
            }
            $isian[$empId] = ['baris' => $baris, 'nilai' => $nilai, 'nama' => trim((string) $sheet->getCell('B' . $baris)->getValue())];
        }

        $isian = array_diff_key($isian, $ganda);

        // Pengaman: ID & nama harus cocok dengan data karyawan (ID yang diubah tidak boleh menimpa slip orang lain).
        $namaDb = DB::table('siapmst_employers')->whereIn('emp_id', array_map('strval', array_keys($isian)))->pluck('emp_name', 'emp_id');
        foreach ($isian as $empId => $data) {
            if (isset($namaDb[$empId]) && mb_strtoupper(trim($namaDb[$empId])) !== mb_strtoupper($data['nama'])) {
                $hasil['galat'][] = "Baris {$data['baris']}: ID {$empId} milik {$namaDb[$empId]}, bukan \"{$data['nama']}\" — baris dilewati.";
                unset($isian[$empId]);
            }
        }

        if ($isian === []) {
            return [...$hasil, 'pesan' => 'Tidak ada baris karyawan di form.'];
        }

        if ($bagian === 'kenaikan_gaji') {
            self::imporKenaikanGaji($isian, $periode, $userId, $hasil);
        } else {
            self::imporSlip($bagian, $isian, $periode, $hasil);
        }

        $hasil['ok'] = true;
        $hasil['pesan'] = "{$hasil['diperbarui']} karyawan diperbarui, {$hasil['sama']} tidak berubah"
            . ($hasil['final'] !== [] ? ', ' . count($hasil['final']) . ' slip Final dilewati' : '')
            . ($hasil['tidakDikenal'] !== [] ? ', ' . count($hasil['tidakDikenal']) . ' ID tidak dikenal' : '')
            . ($hasil['galat'] !== [] ? ', ' . count($hasil['galat']) . ' baris galat' : '') . '.';

        return $hasil;
    }

    /** null (kosong) → null; angka / teks angka gaya Indonesia (1.250.000 / Rp 1.250.000,50) → float; selain itu false. */
    private static function angka(mixed $mentah): float|false|null
    {
        if ($mentah === null || $mentah === '') {
            return null;
        }
        if (is_int($mentah) || is_float($mentah)) {
            return (float) $mentah;
        }
        $teks = preg_replace('/\s|rp\.?/i', '', (string) $mentah);
        if ($teks === '') {
            return null;
        }
        if (preg_match('/^-?\d{1,3}(\.\d{3})+(,\d+)?$/', $teks) || preg_match('/^-?\d+,\d+$/', $teks)) {
            $teks = str_replace(['.', ','], ['', '.'], $teks);
        }

        return is_numeric($teks) ? (float) $teks : false;
    }

    private static function imporSlip(string $bagian, array $isian, string $periode, array &$hasil): void
    {
        $slipList = DB::table('siaptxn_gaji_hdrs as gh')->join('siapmst_employers as em', 'em.emp_id', '=', 'gh.emp_id')
            ->where('gh.periode', $periode)->whereIn('gh.emp_id', array_map('strval', array_keys($isian)))
            ->get(['gh.gaji_id', 'gh.emp_id', 'gh.hari_hadir', 'gh.gaji_status', 'em.emp_name'])->keyBy('emp_id');

        foreach ($isian as $empId => $data) {
            $slip = $slipList[$empId] ?? null;
            if (!$slip) {
                $hasil['tidakDikenal'][] = "Baris {$data['baris']}: {$empId} tidak punya slip bulan ini.";
                continue;
            }
            if ($slip->gaji_status === GajiKaryawan::STATUS_FINAL) {
                $hasil['final'][] = $slip->emp_name;
                continue;
            }

            $gajiId = (int) $slip->gaji_id;
            $nilai = $data['nilai'];

            if ($bagian === 'hari_kerja') {
                $hari = $nilai['hari_kerja'];
                if ($hari === null || (int) $hari === (int) $slip->hari_hadir) {
                    $hasil['sama']++;
                } elseif ($hari != (int) $hari || $hari > 31) {
                    $hasil['galat'][] = "Baris {$data['baris']} ({$empId}): hari kerja harus bilangan bulat 0–31.";
                } else {
                    GajiKaryawan::ubahHariKerja($gajiId, (int) $hari);
                    $hasil['diperbarui']++;
                }
                continue;
            }

            $nilai = array_map(fn($v) => is_string($v) ? $v : round((float) $v, 2), $nilai); // kosong = 0
            $detail = DB::table('siaptxn_gaji_dtls')->where('gaji_id', $gajiId)->orderBy('dtl_id')->get();
            $sekarang = self::nilaiSaatIni($bagian, $slip, $detail);
            $sekarang = array_intersect_key($sekarang, $nilai); // kolom acuan tidak dibandingkan
            $sekarang = array_map(fn($v) => is_string($v) ? $v : round((float) $v, 2), $sekarang);

            if ($bagian === 'lembur' && $nilai['jam'] > 0 && $nilai['upah'] == 0) { // upah dikosongkan → hitung dari jam
                $dasar = (float) $detail->whereIn('kode', self::KODE_DASAR_LEMBUR)->sum(fn($row) => (float) $row->nilai);
                $nilai['upah'] = self::hitungUpahLembur($nilai['jam'], $dasar);
            }
            if ($bagian === 'bank') { // nama bank hanya berarti bila ada angsurannya
                foreach ([1, 2] as $n) {
                    if ($nilai["angs{$n}"] == 0) { $nilai["bank{$n}"] = ''; }
                    if ($sekarang["angs{$n}"] == 0) { $sekarang["bank{$n}"] = ''; }
                }
            }
            if ($nilai == $sekarang) {
                $hasil['sama']++;
                continue;
            }

            DB::transaction(fn() => self::terapkan($bagian, $gajiId, $nilai));
            GajiKaryawan::hitungUlang($gajiId);
            $hasil['diperbarui']++;
        }
    }

    private static function terapkan(string $bagian, int $gajiId, array $nilai): void
    {
        switch ($bagian) {
            case 'lembur':
                $jam = (float) $nilai['jam'];
                $label = rtrim(rtrim(number_format($jam, 2, ',', '.'), '0'), ',');
                self::gantiManual($gajiId, 'D', 'LEMBUR', [['nilai' => $nilai['upah'], 'jumlah' => $jam, 'keterangan' => $jam > 0 ? "{$label} jam" : null]]);
                break;
            case 'bon':
                self::gantiManual($gajiId, 'P', 'KASBON', [['nilai' => $nilai['kasbon']]]);
                break;
            case 'jasa_medik':
                self::gantiManual($gajiId, 'D', 'JASA_MEDIK', [
                    ['nilai' => $nilai['jm_inap'], 'keterangan' => self::JM_INAP],
                    ['nilai' => $nilai['jm_rj'], 'keterangan' => self::JM_RJ],
                    ['nilai' => $nilai['jm_lain']],
                ]);
                break;
            case 'koperasi':
                self::timpaRutin($gajiId, 'P', 'KOPERASI', $nilai['iuran']);
                self::gantiManual($gajiId, 'P', 'ANGS_KOP', [['nilai' => $nilai['angsuran']]]);
                break;
            case 'bank':
                self::gantiManual($gajiId, 'P', 'ANGS_BANK', [
                    ['nilai' => $nilai['angs1'], 'keterangan' => $nilai['bank1']],
                    ['nilai' => $nilai['angs2'], 'keterangan' => $nilai['bank2']],
                ]);
                break;
            case 'bpjs':
                self::timpaRutin($gajiId, 'P', 'BPJS', $nilai['bpjs']);
                self::gantiManual($gajiId, 'P', 'BPJS_TK', [['nilai' => $nilai['bpjs_tk']]]);
                break;
        }
    }

    /** Komponen isian petugas: semua baris kode itu diganti baris dari form (nilai 0 = tidak dibuat). */
    private static function gantiManual(int $gajiId, string $jenis, string $kode, array $barisList): void
    {
        DB::table('siaptxn_gaji_dtls')->where('gaji_id', $gajiId)->where('kode', $kode)->delete();
        foreach ($barisList as $baris) {
            if ((float) $baris['nilai'] > 0) {
                GajiKaryawan::simpanBaris($gajiId, ['jenis' => $jenis, 'kode' => $kode, ...$baris]);
            }
        }
    }

    /**
     * Komponen rutin dari parameter: baris otomatis ditandai "ditimpa" (nilai_manual = 1) supaya
     * generate ulang tidak mengembalikannya ke nilai parameter. 0 = tetap ada baris bernilai 0.
     */
    private static function timpaRutin(int $gajiId, string $jenis, string $kode, float $nilai): void
    {
        DB::table('siaptxn_gaji_dtls')->where('gaji_id', $gajiId)->where('kode', $kode)->where('otomatis', '0')->delete();
        $otomatis = DB::table('siaptxn_gaji_dtls')->where('gaji_id', $gajiId)->where('kode', $kode)->where('otomatis', '1')->orderBy('dtl_id')->get();

        if ($otomatis->isEmpty()) {
            if ($nilai > 0) {
                GajiKaryawan::simpanBaris($gajiId, ['jenis' => $jenis, 'kode' => $kode, 'nilai' => $nilai, 'otomatis' => '1', 'nilai_manual' => '1']);
            }
            return;
        }

        DB::table('siaptxn_gaji_dtls')->whereIn('dtl_id', $otomatis->slice(1)->pluck('dtl_id')->all() ?: [0])->delete();
        DB::table('siaptxn_gaji_dtls')->where('dtl_id', $otomatis->first()->dtl_id)->update([
            'nilai' => $jenis === 'P' ? -abs($nilai) : abs($nilai), 'nilai_manual' => '1', 'updated_at' => now(),
        ]);
    }

    /** Kenaikan gaji: parameter diperbarui; slip Draft bulan terpilih disusun ulang dari parameter baru. */
    private static function imporKenaikanGaji(array $isian, string $periode, ?int $userId, array &$hasil): void
    {
        $kolom = ['pokok_baru' => 'gaji_pokok', 'struktural_baru' => 'tunjangan_struktural', 'profesi_baru' => 'tunjangan_profesi'];
        $parameterList = DB::table('siapmst_gaji_karyawans as gk')->join('siapmst_employers as em', 'em.emp_id', '=', 'gk.emp_id')
            ->whereIn('gk.emp_id', array_map('strval', array_keys($isian)))->get(['gk.*', 'em.emp_name'])->keyBy('emp_id');
        $slipList = DB::table('siaptxn_gaji_hdrs')->where('periode', $periode)->whereIn('emp_id', array_map('strval', array_keys($isian)))->get(['emp_id', 'gaji_status'])->keyBy('emp_id');

        foreach ($isian as $empId => $data) {
            $parameter = $parameterList[$empId] ?? null;
            if (!$parameter) {
                $hasil['tidakDikenal'][] = "Baris {$data['baris']}: {$empId} belum punya parameter gaji.";
                continue;
            }

            $ubah = [];
            foreach ($kolom as $key => $kolomDb) {
                $baru = $data['nilai'][$key] ?? null;
                if ($baru !== null && round($baru, 2) !== round((float) $parameter->$kolomDb, 2)) {
                    $ubah[$kolomDb] = round($baru, 2);
                }
            }
            if ($ubah === []) {
                $hasil['sama']++;
                continue;
            }

            DB::table('siapmst_gaji_karyawans')->where('emp_id', $empId)->update([...$ubah, 'updated_by' => $userId, 'updated_at' => now()]);
            $hasil['diperbarui']++;

            $slip = $slipList[$empId] ?? null;
            if ($slip?->gaji_status === GajiKaryawan::STATUS_FINAL) {
                $hasil['final'][] = $parameter->emp_name . ' (parameter diubah, slip Final tidak)';
            } elseif ($slip) {
                GajiKaryawan::generate($periode, null, $userId, $empId);
            }
        }
    }
}
