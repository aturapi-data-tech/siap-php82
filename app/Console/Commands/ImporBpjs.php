<?php

namespace App\Console\Commands;

use App\Support\PengaturanGaji;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Isi kepesertaan BPJS master karyawan (siapmst_gaji_karyawans.bpjs_*) dari sheet "BPJS" workbook gaji lama.
 * Per baris: nama C, upah K, dasar JKN R ("Gaji Dasar hitung BPJS KES"), keluarga tambahan S, JKN karyawan U,
 * JP karyawan AE, total potongan AF. Peserta JKN = U > 0; peserta JP = AE > 0; dasar JKN disimpan hanya bila ≠ UMK.
 * Karyawan yang cocok: potongan "Asuransi" rutin (yang dulu memuat angka BPJS) DIKOSONGKAN supaya tidak dobel.
 *
 *   php artisan gaji:impor-bpjs ~/Downloads/1026gj.xlsx            (pratinjau)
 *   php artisan gaji:impor-bpjs ~/Downloads/1026gj.xlsx --simpan --alias="Nama di Excel=MDN-xxx"
 */
class ImporBpjs extends Command
{
    protected $signature = 'gaji:impor-bpjs {file : Workbook gaji (.xlsx) berisi sheet BPJS}
        {--sheet=BPJS : Nama sheet}
        {--dari=8 : Baris pertama data}
        {--alias=* : Pemetaan nama tak cocok, format "Nama di Excel=EMP_ID"}
        {--simpan : Tulis ke master (tanpa ini hanya pratinjau)}';

    protected $description = 'Isi kepesertaan BPJS (JKN, keluarga, dasar, JP) master karyawan dari sheet BPJS workbook gaji lama';

    public function handle(): int
    {
        $berkas = $this->argument('file');
        if (!is_file($berkas)) {
            $this->error("Berkas tidak ditemukan: {$berkas}");
            return self::FAILURE;
        }
        ini_set('memory_limit', '3G');
        $reader = IOFactory::createReader('Xlsx');
        $reader->setLoadSheetsOnly([$this->option('sheet')]);
        $sheet = $reader->load($berkas)->getSheetByName($this->option('sheet'));
        if (!$sheet) {
            $this->error('Sheet ' . $this->option('sheet') . ' tidak ada.');
            return self::FAILURE;
        }
        $nilai = function (string $kolom, int $baris) use ($sheet) {
            $sel = $sheet->getCell($kolom . $baris);
            return $sel->isFormula() ? $sel->getOldCalculatedValue() : $sel->getValue();
        };
        $umk = (float) PengaturanGaji::ambil()->umk;

        $norm = fn(?string $nama) => preg_replace('/[^A-Z]/', '', mb_strtoupper(preg_replace(['/,.*$/', '/\b(DR|DRG|H|HJ|NS|S|KEP|AMD|SKM|SPB|SPAN)\.?\s+/i'], '', (string) $nama)));
        $karyawan = DB::table('siapmst_employers as em')->leftJoin('siapmst_gaji_karyawans as gk', 'gk.emp_id', '=', 'em.emp_id')
            ->get(['em.emp_id', 'em.emp_name', 'em.active_status', 'gk.potongan_asuransi', DB::raw('gk.emp_id as parameter_emp_id')]);
        $peta = $karyawan->groupBy(fn($row) => $norm($row->emp_name));
        $byId = $karyawan->keyBy('emp_id');
        $alias = [];
        foreach ((array) $this->option('alias') as $pasangan) {
            [$nama, $empId] = array_map('trim', explode('=', $pasangan, 2) + [1 => '']);
            if (!isset($byId[$empId])) {
                $this->error("Alias \"{$pasangan}\": ID {$empId} tidak ada.");
                return self::FAILURE;
            }
            $alias[$norm($nama)] = $empId;
        }

        $hasil = [];
        $tabel = [];
        $lewat = [];
        for ($baris = (int) $this->option('dari'); $baris <= $sheet->getHighestDataRow(); $baris++) {
            $nama = trim((string) $nilai('C', $baris));
            $af = (float) $nilai('AF', $baris);
            $upah = (float) $nilai('K', $baris);
            if ($nama === '' || is_numeric($nama) || mb_strlen($nama) < 3 || ($af < 1 && $upah < 1)) {
                continue; // judul / kosong / bukan peserta
            }
            $data = [
                'bpjs_jkn' => (float) $nilai('U', $baris) > 0 ? '1' : '0',
                'bpjs_keluarga' => max(0, (int) $nilai('S', $baris)),
                'bpjs_jkn_dasar' => (float) $nilai('R', $baris) > 0 && abs((float) $nilai('R', $baris) - $umk) > 1 ? round((float) $nilai('R', $baris), 2) : null,
                'bpjs_jp' => (float) $nilai('AE', $baris) > 0 ? '1' : '0',
            ];
            $ringkas = ($data['bpjs_jkn'] === '1' ? 'JKN' . ($data['bpjs_keluarga'] > 0 ? "+{$data['bpjs_keluarga']}" : '') . ($data['bpjs_jkn_dasar'] ? ' dasar ' . number_format($data['bpjs_jkn_dasar'], 0, ',', '.') : '') : '-')
                . ' · ' . ($data['bpjs_jp'] === '1' ? 'JP' : '-') . ' · workbook ' . number_format($af, 0, ',', '.');

            $kunci = $norm($nama);
            $empId = $alias[$kunci] ?? null;
            if (!$empId) {
                if (($peta[$kunci] ?? collect())->count() === 1) {
                    $empId = $peta[$kunci]->first()->emp_id;
                } else {
                    $awalan = $peta->filter(fn($rows, $k) => $k !== '' && (str_starts_with($k, $kunci) || str_starts_with($kunci, $k)))->flatten(1);
                    $empId = $awalan->count() === 1 ? $awalan->first()->emp_id : null;
                }
            }
            if (!$empId) {
                $lewat[] = [$baris, $nama, $ringkas, 'tidak cocok nama'];
                continue;
            }
            if ($byId[$empId]->parameter_emp_id === null) {
                $lewat[] = [$baris, $nama, $ringkas, "{$empId} belum punya parameter gaji"];
                continue;
            }
            if (isset($hasil[$empId])) {
                $lewat[] = [$baris, $nama, $ringkas, "ganda: {$empId} sudah dari baris {$hasil[$empId]['baris']}"];
                continue;
            }
            $hasil[$empId] = ['baris' => $baris, 'data' => $data, 'asuransi' => (float) $byId[$empId]->potongan_asuransi];
            $tabel[] = [$baris, $nama, $empId, $byId[$empId]->emp_name . ($byId[$empId]->active_status === '1' ? '' : ' (nonaktif)'), $ringkas, number_format((float) $byId[$empId]->potongan_asuransi, 0, ',', '.')];
        }

        $this->table(['Baris', 'Nama di Excel', 'ID', 'Nama di SIAP', 'Kepesertaan', 'Asuransi SIAP (dikosongkan)'], $tabel);
        $this->info(count($hasil) . ' karyawan cocok.');
        if ($lewat !== []) {
            $this->warn(count($lewat) . ' baris TIDAK diimpor:');
            $this->table(['Baris', 'Nama di Excel', 'Kepesertaan', 'Alasan'], $lewat);
        }
        if (!$this->option('simpan')) {
            $this->comment('Pratinjau saja. Tambah --simpan untuk menulis ke master.');
            return self::SUCCESS;
        }

        foreach ($hasil as $empId => $row) {
            DB::table('siapmst_gaji_karyawans')->where('emp_id', $empId)->update([...$row['data'], 'potongan_asuransi' => 0, 'updated_at' => now()]);
        }
        $this->info('Kepesertaan BPJS disimpan untuk ' . count($hasil) . ' karyawan; potongan Asuransi rutin mereka dikosongkan (diganti hitungan otomatis).');

        return self::SUCCESS;
    }
}
