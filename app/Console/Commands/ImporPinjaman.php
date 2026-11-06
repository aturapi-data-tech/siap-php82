<?php

namespace App\Console\Commands;

use App\Support\PinjamanKaryawan;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Isi buku pinjaman (siaptxn_pinjamans) dari workbook koperasi "Angsuran Tiap Bulan Potong Gaji".
 * Sheet "1%" dan "0,8%": nama di B, pokok C, sewa modal / bulan D (rumus =$C*C$1 → tarif sheet; angka → tarif dihitung;
 * 0 → tanpa sewa modal), lama bulan E, bulan awal angsuran J (tanggal Excel), catatan K ("LUNAS OKT", "TF MELUNASI BULAN 8").
 *
 * Nama dicocokkan ke Master Karyawan (huruf saja, tanpa gelar "DR." / "H."); nama singkat dicocokkan sebagai AWALAN nama
 * bila hanya satu karyawan yang cocok. Sisanya dipetakan lewat --alias "Nama di Excel=MDN-xxx". Tanpa --simpan hanya pratinjau.
 * Catatan "LUNAS <BLN>" → dilunasi mulai bulan itu (tahun = --tahun-lunas); "MELUNASI BULAN n" → mulai bulan n.
 * Baris yang sudah ada (karyawan + sumber + pokok + bulan awal sama) dilewati — aman diulang.
 * Praktik koperasi (terbukti di workbook gaji: satu angsuran per orang): pinjaman baru melunasi sisa pinjaman lama yang
 * masih berjalan → setelah impor, pinjaman lama yang tumpang tindih ditandai dilunasi (matikan dengan --tanpa-tutup-lama).
 *
 *   php artisan gaji:impor-pinjaman ~/Downloads/angsuran.xlsx
 *   php artisan gaji:impor-pinjaman ~/Downloads/angsuran.xlsx --alias="PUPUT SSOLEKHA=MDN-112" --simpan
 */
class ImporPinjaman extends Command
{
    protected $signature = 'gaji:impor-pinjaman {file : Workbook koperasi (.xlsx)}
        {--sheet=* : Nama sheet (bawaan: "1%" dan "0,8%")}
        {--sumber=KOP : Kode sumber pinjaman di master ketentuan}
        {--tahun-lunas=2026 : Tahun untuk catatan "LUNAS <BLN>" / "MELUNASI BULAN n"}
        {--alias=* : Pemetaan nama tak cocok, format "Nama di Excel=EMP_ID"}
        {--tanpa-tutup-lama : Jangan tandai pinjaman lama yang tumpang tindih sebagai dilunasi oleh pinjaman baru}
        {--simpan : Tulis ke buku pinjaman (tanpa ini hanya pratinjau)}';

    protected $description = 'Isi buku pinjaman karyawan dari workbook koperasi (sheet 1% / 0,8%)';

    private const BULAN = ['JAN' => 1, 'FEB' => 2, 'MAR' => 3, 'APR' => 4, 'MEI' => 5, 'JUN' => 6, 'JUL' => 7, 'AGU' => 8, 'AGS' => 8, 'SEP' => 9, 'OKT' => 10, 'NOV' => 11, 'DES' => 12];

    public function handle(): int
    {
        $berkas = $this->argument('file');
        if (!is_file($berkas)) {
            $this->error("Berkas tidak ditemukan: {$berkas}");
            return self::FAILURE;
        }
        $sumber = PinjamanKaryawan::sumber()[strtoupper($this->option('sumber'))] ?? null;
        if (!$sumber) {
            $this->error('Sumber ' . $this->option('sumber') . ' tidak ada di ketentuan pinjaman.');
            return self::FAILURE;
        }
        $sheetList = (array) $this->option('sheet') ?: ['1%', '0,8%'];
        $reader = IOFactory::createReader('Xlsx');
        $reader->setLoadSheetsOnly($sheetList);
        $workbook = $reader->load($berkas);

        $norm = fn(?string $nama) => preg_replace('/[^A-Z]/', '', mb_strtoupper(preg_replace(['/,.*$/', '/\b(DR|DRG|H|HJ|NS|S|KEP|AMD|SKM)\.?\s+/i'], '', (string) $nama)));
        $karyawan = DB::table('siapmst_employers')->get(['emp_id', 'emp_name', 'active_status']);
        $peta = $karyawan->groupBy(fn($row) => $norm($row->emp_name));
        $byId = $karyawan->keyBy('emp_id');

        $alias = [];
        foreach ((array) $this->option('alias') as $pasangan) {
            [$nama, $empId] = array_map('trim', explode('=', $pasangan, 2) + [1 => '']);
            if (!isset($byId[$empId])) {
                $this->error("Alias \"{$pasangan}\": ID {$empId} tidak ada di Master Karyawan.");
                return self::FAILURE;
            }
            $alias[$norm($nama)] = $empId;
        }

        $tahunLunas = (int) $this->option('tahun-lunas');
        $ada = DB::table('siaptxn_pinjamans')->where('sumber_kode', $sumber->sumber_kode)->get(['emp_id', 'pokok', 'bulan_awal'])
            ->map(fn($row) => $row->emp_id . '|' . (int) $row->pokok . '|' . $row->bulan_awal)->flip();

        $hasil = [];
        $tabel = [];
        $lewat = [];
        $sudah = 0;
        foreach ($sheetList as $namaSheet) {
            $sheet = $workbook->getSheetByName($namaSheet);
            if (!$sheet) {
                $this->error("Sheet {$namaSheet} tidak ada.");
                return self::FAILURE;
            }
            $tarifSheet = (float) $sheet->getCell('C1')->getValue() * 100; // "bunga pinjaman" 0.01 → 1 %
            for ($baris = 4; $baris <= $sheet->getHighestDataRow(); $baris++) {
                $nama = trim((string) $sheet->getCell("B{$baris}")->getValue());
                $pokok = $sheet->getCell("C{$baris}")->getValue();
                $lama = $sheet->getCell("E{$baris}")->getValue();
                $tanggal = $sheet->getCell("J{$baris}")->getValue();
                if ($nama === '' || !is_numeric($pokok) || (float) $pokok <= 0 || !is_numeric($lama) || !is_numeric($tanggal)) {
                    continue; // judul / total / baris kosong
                }
                $selSewa = $sheet->getCell("D{$baris}");
                $sewa = $selSewa->isFormula() ? $selSewa->getOldCalculatedValue() : $selSewa->getValue();
                $persen = $selSewa->isFormula() || !is_numeric($sewa) ? $tarifSheet : round((float) $sewa / (float) $pokok * 100, 3);
                $bulanAwal = ExcelDate::excelToDateTimeObject($tanggal)->format('Y-m');
                $catatan = trim((string) $sheet->getCell("K{$baris}")->getValue());
                $lunas = $this->bulanLunas($catatan, $tahunLunas);

                $kunci = $norm($nama);
                $empId = $alias[$kunci] ?? $this->cocokkan($kunci, $peta);
                $hitung = PinjamanKaryawan::hitung((float) $pokok, $persen, (int) $lama, (int) $sumber->pembulatan);
                $ringkas = number_format((float) $pokok, 0, ',', '.') . " · {$persen}% · {$lama} bln · mulai {$bulanAwal} · angs " . number_format($hitung['angsuran'], 0, ',', '.')
                    . ($lunas ? " · dilunasi {$lunas}" : '') . ($catatan !== '' ? " · \"{$catatan}\"" : '');
                $angsuranExcel = $sheet->getCell("I{$baris}")->isFormula() ? $sheet->getCell("I{$baris}")->getOldCalculatedValue() : $sheet->getCell("I{$baris}")->getValue();
                if (is_numeric($angsuranExcel) && abs((float) $angsuranExcel - $hitung['angsuran']) >= 1) {
                    $ringkas .= ' · BEDA Excel ' . number_format((float) $angsuranExcel, 0, ',', '.');
                }

                if (!$empId) {
                    $lewat[] = ["{$namaSheet}:{$baris}", $nama, $ringkas, $this->saran($nama, $karyawan)];
                    continue;
                }
                if (isset($ada[$empId . '|' . (int) $pokok . '|' . $bulanAwal])) {
                    $sudah++;
                    continue;
                }
                $hasil[] = ['emp_id' => $empId, 'sumber_kode' => $sumber->sumber_kode, 'pokok' => (float) $pokok, 'persen_sewa' => $persen,
                    'lama_bulan' => (int) $lama, 'bulan_awal' => $bulanAwal, 'lunas_periode' => $lunas,
                    'keterangan' => trim("Impor Excel koperasi sheet {$namaSheet}" . ($catatan !== '' ? " — {$catatan}" : ''))];
                $tabel[] = ["{$namaSheet}:{$baris}", $nama, $empId, $byId[$empId]->emp_name . ($byId[$empId]->active_status === '1' ? '' : ' (nonaktif)'), $ringkas];
            }
        }

        $this->table(['Sheet:Baris', 'Nama di Excel', 'ID', 'Nama di SIAP', 'Pinjaman'], $tabel);
        $this->info(count($hasil) . ' pinjaman cocok' . ($sudah > 0 ? ", {$sudah} sudah ada di buku (dilewati)" : '') . '.');
        if ($lewat !== []) {
            $this->warn(count($lewat) . ' baris TIDAK diimpor — petakan dengan --alias="Nama di Excel=EMP_ID" bila memang orangnya:');
            $this->table(['Sheet:Baris', 'Nama di Excel', 'Pinjaman', 'Saran'], $lewat);
        }
        if (!$this->option('simpan')) {
            $this->comment('Pratinjau saja. Tambah --simpan untuk menulis ke buku pinjaman.');
            return self::SUCCESS;
        }

        $gagal = 0;
        foreach ($hasil as $data) {
            $simpan = PinjamanKaryawan::simpan(null, $data, null);
            if (!$simpan['ok']) {
                $gagal++;
                $this->error("{$data['emp_id']} {$data['bulan_awal']}: {$simpan['pesan']}");
            }
        }
        $this->info((count($hasil) - $gagal) . ' pinjaman ditulis ke buku pinjaman.');

        if (!$this->option('tanpa-tutup-lama')) {
            $ditutup = 0;
            foreach (DB::table('siaptxn_pinjamans')->where('sumber_kode', $sumber->sumber_kode)->orderBy('bulan_awal')->get(['pinjaman_id', 'emp_id', 'bulan_awal']) as $row) {
                $ditutup += PinjamanKaryawan::tutupPinjamanLama($row->emp_id, $sumber->sumber_kode, $row->bulan_awal, (int) $row->pinjaman_id, null);
            }
            $this->info("{$ditutup} pinjaman lama yang tumpang tindih ditandai dilunasi oleh pinjaman baru.");
        }

        return $gagal > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** Nama persis → satu karyawan; bila tidak, nama Excel sebagai AWALAN nama karyawan (hanya bila tepat satu yang cocok). */
    private function cocokkan(string $kunci, $peta): ?string
    {
        if ($kunci === '') {
            return null;
        }
        if (($peta[$kunci] ?? collect())->count() === 1) {
            return $peta[$kunci]->first()->emp_id;
        }
        $awalan = $peta->filter(fn($rows, $namaNorm) => str_starts_with($namaNorm, $kunci))->flatten(1);

        return $awalan->count() === 1 ? $awalan->first()->emp_id : null;
    }

    /** "LUNAS OKT" → YYYY-10 ; "TF MELUNASI BULAN 8" → YYYY-08 ; selain itu null. */
    private function bulanLunas(string $catatan, int $tahun): ?string
    {
        $teks = mb_strtoupper($catatan);
        if (preg_match('/MELUNASI\s+BULAN\s+(\d{1,2})/', $teks, $m)) {
            return sprintf('%04d-%02d', $tahun, (int) $m[1]);
        }
        if (preg_match('/LUNAS\s+([A-Z]{3})/', $teks, $m) && isset(self::BULAN[$m[1]])) {
            return sprintf('%04d-%02d', $tahun, self::BULAN[$m[1]]);
        }

        return null;
    }

    /** Dua karyawan aktif dengan nama paling mirip — bahan --alias. */
    private function saran(string $nama, $karyawan): string
    {
        $cari = mb_strtoupper($nama);
        $kata = array_filter(preg_split('/\s+/', $cari), fn($k) => mb_strlen($k) > 2);

        return $karyawan->where('active_status', '1')
            ->map(function ($row) use ($cari, $kata) {
                $skor = 0;
                foreach ($kata as $k) {
                    $skor += str_contains(mb_strtoupper($row->emp_name), $k) ? 2 : (str_contains(mb_strtoupper($row->emp_name), mb_substr($k, 0, 4)) ? 1 : 0);
                }
                similar_text($cari, mb_strtoupper($row->emp_name), $persen);

                return [$row, $skor + $persen / 100];
            })
            ->filter(fn($pasangan) => $pasangan[1] >= 1)->sortByDesc(fn($pasangan) => $pasangan[1])->take(2)
            ->map(fn($pasangan) => "{$pasangan[0]->emp_id} {$pasangan[0]->emp_name}")->implode(' / ') ?: '-';
    }
}
