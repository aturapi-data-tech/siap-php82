<?php

namespace App\Console\Commands;

use App\Support\JasaMedik;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Isi MASTER poin Jasa Medik (siapmst_jm_poins) dari sheet "JaMed" workbook gaji lama.
 * Sheet JaMed: nama di kolom C, poin per pos di E (Rawat Inap), G (UGD), I (Poli RJ), K (Resep), M (Lab),
 * baris 14–101 (rentang yang dijumlah Excel; di bawahnya ada blok lain: pegawai baru, dokter, jumlah).
 *
 * Nama dicocokkan ke Master Karyawan (huruf saja, tanpa gelar di belakang koma). Nama yang ditulis
 * singkat / salah eja dipetakan lewat --alias "Nama di Excel=MDN-xxx". Tanpa --simpan hanya pratinjau.
 *
 *   php artisan gaji:impor-poin-jm ~/Downloads/0926gj.xlsx
 *   php artisan gaji:impor-poin-jm ~/Downloads/0926gj.xlsx --alias="Anjab=MDN-169" --alias="Umar=MDN-141" --simpan
 */
class ImporPoinJm extends Command
{
    protected $signature = 'gaji:impor-poin-jm {file : Workbook gaji (.xlsx) berisi sheet JaMed}
        {--sheet=JaMed : Nama sheet}
        {--dari=14 : Baris pertama data karyawan}
        {--sampai=101 : Baris terakhir — sama dengan rentang total poin di Excel (E12 = SUM(E14:E101))}
        {--alias=* : Pemetaan nama tak cocok, format "Nama di Excel=EMP_ID"}
        {--simpan : Tulis ke master (tanpa ini hanya pratinjau)}';

    protected $description = 'Isi master poin Jasa Medik dari sheet JaMed workbook gaji lama';

    private const KOLOM_POS = ['RI' => 'E', 'UGD' => 'G', 'POLI' => 'I', 'RESEP' => 'K', 'LAB' => 'M'];

    public function handle(): int
    {
        $berkas = $this->argument('file');
        if (!is_file($berkas)) {
            $this->error("Berkas tidak ditemukan: {$berkas}");
            return self::FAILURE;
        }

        $reader = IOFactory::createReader('Xlsx');
        $reader->setLoadSheetsOnly([$this->option('sheet')]);
        $sheet = $reader->load($berkas)->getSheetByName($this->option('sheet'));
        if (!$sheet) {
            $this->error('Sheet ' . $this->option('sheet') . ' tidak ada.');
            return self::FAILURE;
        }

        $norm = fn(?string $nama) => preg_replace('/[^A-Z]/', '', mb_strtoupper(preg_replace('/,.*$/', '', (string) $nama)));
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

        $hasil = [];
        $tabel = [];
        $lewat = [];
        $sampai = min((int) $this->option('sampai'), $sheet->getHighestDataRow());
        for ($baris = (int) $this->option('dari'); $baris <= $sampai; $baris++) {
            $poin = [];
            foreach (self::KOLOM_POS as $kode => $kolom) {
                $sel = $sheet->getCell($kolom . $baris);
                $nilai = $sel->isFormula() ? $sel->getOldCalculatedValue() : $sel->getValue();
                $poin[$kode] = is_numeric($nilai) ? (float) $nilai : 0.0;
            }
            if (array_sum($poin) <= 0) {
                continue; // judul kelompok / baris kosong / total
            }

            $nama = trim((string) $sheet->getCell('C' . $baris)->getValue());
            $kunci = $norm($nama);
            $empId = $alias[$kunci] ?? (($peta[$kunci] ?? collect())->count() === 1 ? $peta[$kunci]->first()->emp_id : null);
            $ringkas = collect($poin)->filter()->map(fn($nilai, $kode) => "{$kode} " . rtrim(rtrim(number_format($nilai, 2, ',', ''), '0'), ','))->implode(', ');

            if (!$empId) {
                $lewat[] = [$baris, $nama !== '' ? $nama : '(tanpa nama)', $ringkas, $nama !== '' ? $this->saran($nama, $karyawan) : '-'];
                continue;
            }
            if (isset($hasil[$empId])) {
                $lewat[] = [$baris, $nama, $ringkas, "ganda: {$empId} sudah dari baris {$hasil[$empId]['baris']}"];
                continue;
            }
            $hasil[$empId] = ['baris' => $baris, 'poin' => $poin];
            $tabel[] = [$baris, $nama, $empId, $byId[$empId]->emp_name . ($byId[$empId]->active_status === '1' ? '' : ' (nonaktif)'), $ringkas];
        }

        $this->table(['Baris', 'Nama di Excel', 'ID', 'Nama di SIAP', 'Poin'], $tabel);
        $this->info(count($hasil) . ' karyawan cocok.');
        if ($lewat !== []) {
            $this->warn(count($lewat) . ' baris berpoin TIDAK diimpor — petakan dengan --alias="Nama di Excel=EMP_ID" bila memang orangnya:');
            $this->table(['Baris', 'Nama di Excel', 'Poin', 'Saran'], $lewat);
        }

        $total = [];
        foreach (array_keys(self::KOLOM_POS) as $kode) {
            $total[] = $kode . ' ' . rtrim(rtrim(number_format(array_sum(array_map(fn($row) => $row['poin'][$kode], $hasil)), 2, ',', ''), '0'), ',');
        }
        $this->line('Total poin yang diimpor: ' . implode(' · ', $total));

        if (!$this->option('simpan')) {
            $this->comment('Pratinjau saja. Tambah --simpan untuk menulis ke master poin.');
            return self::SUCCESS;
        }

        foreach ($hasil as $empId => $data) {
            JasaMedik::simpanMasterPoin($empId, $data['poin'], null);
        }
        $this->info('Master poin Jasa Medik disimpan untuk ' . count($hasil) . ' karyawan.');

        return self::SUCCESS;
    }

    /** Dua karyawan aktif dengan nama paling mirip — bahan --alias. */
    private function saran(string $nama, $karyawan): string
    {
        $cari = mb_strtoupper($nama);
        $kata = array_filter(preg_split('/\s+/', $cari), fn($k) => mb_strlen($k) > 1);

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
