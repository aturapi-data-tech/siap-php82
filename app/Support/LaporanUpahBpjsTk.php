<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

/**
 * Laporan upah BPJS Ketenagakerjaan — berkas "template_upah_<NPP>.xlsx" yang diunggah ke SIPP Online tiap bulan.
 * Bentuknya ditiru persis: sheet "Petunjuk Pengisian" + sheet "data_upah" (NIK, ID_PEGAWAI, KPJ, KODE_TK, NAMA_LENGKAP,
 * TGL_LAHIR, UPAH, RAPEL, BLTH, NPP), kolom nomor bertipe teks, tanggal "dd-mm-yyyy".
 *
 * Sumber data: master karyawan (NIK, tanggal lahir) + parameter gaji (KPJ, kode TK, ID pegawai SIPP, UPAH yang dilaporkan)
 * + NPP di Ketentuan BPJS. UPAH = nilai tetap per karyawan yang didaftarkan ke BPJS TK (bukan hitungan slip) — diubah di
 * Master Karyawan → Gaji → kotak BPJS. RAPEL selalu 0 (diisi manual di berkas bila ada).
 * BLTH (bulan lapor) = bulan kerja + 1 = bulan gaji dibayar, sama dengan bulan iuran yang dipotong di slip bulan kerja itu.
 *
 * Pengisian awal dari berkas SIPP yang sudah ada: impor() / `php artisan gaji:impor-upah-bpjs-tk`.
 */
class LaporanUpahBpjsTk
{
    public const SHEET_PETUNJUK = 'Petunjuk Pengisian';
    public const SHEET_DATA = 'data_upah';
    public const KOLOM = ['NIK', 'ID_PEGAWAI', 'KPJ', 'KODE_TK', 'NAMA_LENGKAP', 'TGL_LAHIR', 'UPAH', 'RAPEL', 'BLTH', 'NPP'];
    private const LEBAR = ['A' => 18, 'B' => 15, 'C' => 13, 'D' => 18, 'E' => 30, 'F' => 12, 'G' => 10, 'H' => 10, 'I' => 12, 'J' => 12];
    private const FORMAT_TANGGAL = 'dd\-mm\-yyyy';

    /** Bulan lapor ke BPJS TK = bulan gaji dibayar = bulan kerja + 1. */
    public static function bulanLapor(string $periode): Carbon
    {
        return GajiKaryawan::awalPeriode($periode)->addMonthNoOverflow();
    }

    public static function namaBerkas(string $periode): string
    {
        $npp = trim((string) PengaturanGaji::ambil()->bpjs_tk_npp) ?: 'siap';

        return "template_upah_{$npp}_" . self::bulanLapor($periode)->format('Y-m') . '.xlsx';
    }

    /**
     * Peserta BPJS TK = karyawan aktif yang punya nomor KPJ atau kode TK, urut nama.
     * @return Collection<int, object{emp_id, emp_name, emp_nik_ktp, emp_birth_date, bpjs_tk_kpj, bpjs_tk_kode, bpjs_tk_id_pegawai, bpjs_tk_upah}>
     */
    public static function pesertaList(): Collection
    {
        return DB::table('siapmst_gaji_karyawans as gk')
            ->join('siapmst_employers as em', 'em.emp_id', '=', 'gk.emp_id')
            ->where('em.active_status', '1')
            ->where(fn($query) => $query->whereNotNull('gk.bpjs_tk_kpj')->orWhereNotNull('gk.bpjs_tk_kode'))
            ->orderBy('em.emp_name')
            ->get(['gk.emp_id', 'em.emp_name', 'em.emp_nik_ktp', 'em.emp_birth_date', 'gk.bpjs_tk_kpj', 'gk.bpjs_tk_kode', 'gk.bpjs_tk_id_pegawai', 'gk.bpjs_tk_upah']);
    }

    // ─── EKSPOR ───────────────────────────────────────────────────────────────

    /** Null bila belum ada peserta (KPJ / kode TK belum diisi di parameter gaji). */
    public static function ekspor(string $periode): ?Spreadsheet
    {
        $pesertaList = self::pesertaList();
        if ($pesertaList->isEmpty()) {
            return null;
        }
        $npp = trim((string) PengaturanGaji::ambil()->bpjs_tk_npp);
        $blth = self::bulanLapor($periode)->format('01-m-Y');

        $book = new Spreadsheet();
        $book->getProperties()->setCreator(Identitas::namaPendek())->setTitle('Template upah BPJS Ketenagakerjaan');

        $petunjuk = $book->getActiveSheet();
        $petunjuk->setTitle(self::SHEET_PETUNJUK);
        $petunjuk->setCellValue('A3', 'Langkah Penggunaan Sheet "Data Upah"');
        $petunjuk->mergeCells('A3:B3');
        $petunjuk->setCellValue('A4', 'Pengisian Column');
        $petunjuk->setCellValue('B4', 'Petunjuk');
        $petunjuk->setCellValue('A5', 'UPAH');
        $petunjuk->setCellValue('B5', 'Berisi upah dari tenaga kerja. Wajib diisi. Decimal menggunakan tanda titik (.) Contoh : 1500000,99');
        $petunjuk->setCellValue('A6', 'RAPEL');
        $petunjuk->setCellValue('B6', 'Berisi rapel dari tenaga kerja. Decimal menggunakan tanda titik (.) Contoh : 1500000,99');
        $petunjuk->setCellValue('A8', '* Perubahan data hanya pada kolom upah dan rapel, susunan data tidak boleh dirubah atau disorting. ');
        $petunjuk->mergeCells('A8:B8');
        $petunjuk->setCellValue('A9', '* Untuk pengisian data tenaga kerja terdapat di sheet data_upah ');
        $petunjuk->mergeCells('A9:B9');
        $petunjuk->getColumnDimension('A')->setWidth(20);
        $petunjuk->getColumnDimension('B')->setWidth(100);

        $data = $book->createSheet();
        $data->setTitle(self::SHEET_DATA);
        foreach (self::KOLOM as $i => $judul) {
            $huruf = chr(ord('A') + $i);
            $data->setCellValueExplicit("{$huruf}1", $judul, DataType::TYPE_STRING);
            $data->getColumnDimension($huruf)->setWidth(self::LEBAR[$huruf]);
        }
        $baris = 2;
        foreach ($pesertaList as $peserta) {
            $data->setCellValueExplicit("A{$baris}", trim((string) $peserta->emp_nik_ktp), DataType::TYPE_STRING);
            $data->setCellValueExplicit("B{$baris}", trim((string) $peserta->bpjs_tk_id_pegawai), DataType::TYPE_STRING);
            $data->setCellValueExplicit("C{$baris}", trim((string) $peserta->bpjs_tk_kpj), DataType::TYPE_STRING);
            $data->setCellValueExplicit("D{$baris}", trim((string) $peserta->bpjs_tk_kode), DataType::TYPE_STRING);
            $data->setCellValueExplicit("E{$baris}", trim((string) $peserta->emp_name), DataType::TYPE_STRING);
            $data->setCellValueExplicit("F{$baris}", $peserta->emp_birth_date ? Carbon::parse($peserta->emp_birth_date)->format('d-m-Y') : '', DataType::TYPE_STRING);
            $data->setCellValue("G{$baris}", round((float) ($peserta->bpjs_tk_upah ?? 0), 2));
            $data->setCellValue("H{$baris}", 0);
            $data->setCellValueExplicit("I{$baris}", $blth, DataType::TYPE_STRING);
            $data->setCellValueExplicit("J{$baris}", $npp, DataType::TYPE_STRING);
            $baris++;
        }
        $akhir = $baris - 1;
        foreach (['A', 'B', 'C', 'D', 'J'] as $huruf) {
            $data->getStyle("{$huruf}1:{$huruf}{$akhir}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
        }
        foreach (['F', 'I'] as $huruf) {
            $data->getStyle("{$huruf}1:{$huruf}{$akhir}")->getNumberFormat()->setFormatCode(self::FORMAT_TANGGAL);
        }
        $book->setActiveSheetIndex(1);

        return $book;
    }

    // ─── IMPOR (pengisian awal dari berkas SIPP) ──────────────────────────────

    /**
     * Baca sheet data_upah berkas SIPP → pasangkan ke karyawan (NIK sama; kalau tidak, nama persis setelah dirapikan; kalau
     * tidak, alias "Nama di Excel=EMP_ID"). Bila $simpan: KPJ, kode TK, ID pegawai, UPAH ditulis ke parameter gaji; NIK & tanggal
     * lahir master diisi hanya bila masih kosong (beda isi dilaporkan, tidak ditimpa); NPP ditulis ke Ketentuan BPJS.
     * @param array<string, string> $alias  nama di Excel (apa adanya) => emp_id
     * @return array{cocok: array<int, array>, lewat: array<int, array>, catatan: array<int, string>, npp: string, disimpan: int}
     */
    public static function impor(string $path, array $alias, bool $simpan, ?int $userId): array
    {
        $sheet = IOFactory::createReader('Xlsx')->load($path)->getSheetByName(self::SHEET_DATA);
        if (!$sheet) {
            throw new \RuntimeException('Sheet "' . self::SHEET_DATA . '" tidak ada — bukan berkas template upah SIPP.');
        }
        foreach (self::KOLOM as $i => $judul) {
            $sel = chr(ord('A') + $i) . '1';
            if (trim((string) $sheet->getCell($sel)->getValue()) !== $judul) {
                throw new \RuntimeException("Judul kolom {$sel} seharusnya {$judul}.");
            }
        }

        $karyawanList = DB::table('siapmst_employers as em')->leftJoin('siapmst_gaji_karyawans as gk', 'gk.emp_id', '=', 'em.emp_id')
            ->get(['em.emp_id', 'em.emp_name', 'em.emp_nik_ktp', 'em.emp_birth_date', 'em.active_status', DB::raw('gk.emp_id as parameter_emp_id'),
                'gk.bpjs_tk_kpj', 'gk.bpjs_tk_kode', 'gk.bpjs_tk_id_pegawai', 'gk.bpjs_tk_upah']);
        $byId = $karyawanList->keyBy('emp_id');
        $byNik = $karyawanList->filter(fn($row) => trim((string) $row->emp_nik_ktp) !== '')->keyBy(fn($row) => trim((string) $row->emp_nik_ktp));
        $byNama = $karyawanList->groupBy(fn($row) => self::normNama($row->emp_name));
        $aliasNormal = [];
        foreach ($alias as $namaExcel => $empId) {
            if (!isset($byId[$empId])) {
                throw new \RuntimeException("Alias \"{$namaExcel}={$empId}\": ID {$empId} tidak ada di master karyawan.");
            }
            $aliasNormal[self::normNama($namaExcel)] = $empId;
        }

        $hasil = ['cocok' => [], 'lewat' => [], 'catatan' => [], 'npp' => '', 'disimpan' => 0];
        $sudah = [];
        for ($baris = 2; $baris <= $sheet->getHighestDataRow(); $baris++) {
            $nilai = fn(string $huruf) => trim((string) $sheet->getCell($huruf . $baris)->getValue());
            $nama = $nilai('E');
            if ($nama === '') {
                continue;
            }
            $nik = $nilai('A');
            $data = ['bpjs_tk_kpj' => $nilai('C') ?: null, 'bpjs_tk_kode' => $nilai('D') ?: null, 'bpjs_tk_id_pegawai' => $nilai('B') ?: null,
                'bpjs_tk_upah' => round((float) str_replace(',', '.', $nilai('G')), 2)];
            $tanggalLahir = self::tanggal($sheet->getCell('F' . $baris)->getValue());
            if ($hasil['npp'] === '' && $nilai('J') !== '') {
                $hasil['npp'] = $nilai('J');
            }

            $karyawan = $nik !== '' ? ($byNik[$nik] ?? null) : null;
            $cara = $karyawan ? 'NIK' : '';
            if (!$karyawan) {
                $kunci = self::normNama($nama);
                if (isset($aliasNormal[$kunci])) {
                    $karyawan = $byId[$aliasNormal[$kunci]];
                    $cara = 'alias';
                } elseif (($byNama[$kunci] ?? collect())->count() === 1) {
                    $karyawan = $byNama[$kunci]->first();
                    $cara = 'nama';
                }
            }
            $ringkas = ($data['bpjs_tk_kpj'] ?? '-') . ' · upah ' . number_format($data['bpjs_tk_upah'], 0, ',', '.');
            if (!$karyawan) {
                $saran = ($byNama[self::normNama($nama)] ?? collect())->map(fn($row) => "{$row->emp_id} {$row->emp_name}")->implode(' / ')
                    ?: $karyawanList->filter(fn($row) => str_starts_with(self::normNama($row->emp_name), mb_substr(self::normNama($nama), 0, 6)))->take(3)->map(fn($row) => "{$row->emp_id} {$row->emp_name}")->implode(' / ');
                $hasil['lewat'][] = [$baris, $nama, $ringkas, 'tidak cocok NIK / nama', $saran];
                continue;
            }
            if ($karyawan->parameter_emp_id === null) {
                $hasil['lewat'][] = [$baris, $nama, $ringkas, "{$karyawan->emp_id} belum punya parameter gaji", ''];
                continue;
            }
            if (isset($sudah[$karyawan->emp_id])) {
                $hasil['lewat'][] = [$baris, $nama, $ringkas, "ganda: {$karyawan->emp_id} sudah dari baris {$sudah[$karyawan->emp_id]}", ''];
                continue;
            }
            $sudah[$karyawan->emp_id] = $baris;

            $catatanBaris = [];
            if ($nik !== '' && trim((string) $karyawan->emp_nik_ktp) !== '' && trim((string) $karyawan->emp_nik_ktp) !== $nik) {
                $catatanBaris[] = 'NIK beda dengan master (' . $karyawan->emp_nik_ktp . '), master tidak diubah';
            }
            $isiNik = $nik !== '' && trim((string) $karyawan->emp_nik_ktp) === '';
            $isiLahir = $tanggalLahir !== null && empty($karyawan->emp_birth_date);
            if ($tanggalLahir !== null && !empty($karyawan->emp_birth_date) && Carbon::parse($karyawan->emp_birth_date)->format('Y-m-d') !== $tanggalLahir->format('Y-m-d')) {
                $catatanBaris[] = 'tanggal lahir beda dengan master (' . Carbon::parse($karyawan->emp_birth_date)->format('d-m-Y') . '), master tidak diubah';
            }
            $hasil['cocok'][] = ['baris' => $baris, 'nama' => $nama, 'emp_id' => $karyawan->emp_id, 'emp_name' => $karyawan->emp_name . ($karyawan->active_status === '1' ? '' : ' (nonaktif)'),
                'cara' => $cara, 'ringkas' => $ringkas, 'data' => $data, 'nik' => $isiNik ? $nik : null, 'tanggal_lahir' => $isiLahir ? $tanggalLahir : null,
                'catatan' => implode('; ', $catatanBaris)];
            foreach ($catatanBaris as $catatan) {
                $hasil['catatan'][] = "Baris {$baris} {$karyawan->emp_id}: {$catatan}";
            }
        }

        if (!$simpan) {
            return $hasil;
        }
        foreach ($hasil['cocok'] as $row) {
            DB::table('siapmst_gaji_karyawans')->where('emp_id', $row['emp_id'])->update([...$row['data'], 'updated_by' => $userId, 'updated_at' => now()]);
            $master = array_filter(['emp_nik_ktp' => $row['nik'], 'emp_birth_date' => $row['tanggal_lahir']?->format('Y-m-d')]);
            if ($master !== []) {
                DB::table('siapmst_employers')->where('emp_id', $row['emp_id'])->update([...$master, 'updated_at' => now()]);
            }
            $hasil['disimpan']++;
        }
        if ($hasil['npp'] !== '') {
            DB::table('siapmst_pengaturan_gajis')->where('id', 1)->update(['bpjs_tk_npp' => mb_substr($hasil['npp'], 0, 20), 'updated_by' => $userId, 'updated_at' => now()]);
        }

        return $hasil;
    }

    /** Huruf saja, tanpa gelar — sama dengan pencocokan nama di impor BPJS / pemetaan dokter. */
    public static function normNama(?string $nama): string
    {
        return preg_replace('/[^A-Z]/', '', mb_strtoupper(preg_replace(['/,.*$/', '/\b(DR|DRG|H|HJ|NS|S|KEP|AMD|SKM|SPB|SPAN)\.?\s+/i'], '', (string) $nama)));
    }

    /** Sel tanggal template: teks "dd-mm-yyyy" atau angka tanggal Excel. */
    private static function tanggal(mixed $mentah): ?Carbon
    {
        if ($mentah === null || $mentah === '') {
            return null;
        }
        if (is_numeric($mentah)) {
            return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $mentah));
        }
        try {
            return Carbon::createFromFormat('d-m-Y', trim((string) $mentah));
        } catch (\Throwable) {
            return null;
        }
    }
}
