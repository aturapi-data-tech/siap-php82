<?php

namespace App\Console\Commands;

use App\Support\LaporanUpahBpjsTk;
use Illuminate\Console\Command;

/**
 * Pengisian awal data BPJS Ketenagakerjaan dari berkas template SIPP Online ("template_upah_<NPP>.xlsx", sheet data_upah):
 * KPJ, kode TK, ID pegawai SIPP, UPAH yang dilaporkan → parameter gaji; NIK & tanggal lahir → master karyawan (hanya bila masih
 * kosong); NPP → Ketentuan BPJS. Sesudahnya laporan bulanan dibuat dari SIAP (Gaji Karyawan → Form Bagian → Upah BPJS TK).
 *
 * Pencocokan: NIK sama → nama persis (huruf saja, tanpa gelar) → --alias "Nama di Excel=EMP_ID". Tanpa --simpan hanya pratinjau.
 *
 *   php artisan gaji:impor-upah-bpjs-tk ~/Downloads/template_upah_NN080468.xlsx
 *   php artisan gaji:impor-upah-bpjs-tk ~/Downloads/template_upah_NN080468.xlsx --alias="YOGIYO P=MDN-071" --simpan
 */
class ImporUpahBpjsTk extends Command
{
    protected $signature = 'gaji:impor-upah-bpjs-tk {file : Berkas template upah SIPP (.xlsx)}
        {--alias=* : Pemetaan nama tak cocok, format "Nama di Excel=EMP_ID"}
        {--simpan : Tulis ke parameter gaji & master (tanpa ini hanya pratinjau)}';

    protected $description = 'Isi KPJ, kode TK, upah lapor BPJS TK (+ NIK & tanggal lahir yang kosong) dari berkas template upah SIPP Online';

    public function handle(): int
    {
        $berkas = $this->argument('file');
        if (!is_file($berkas)) {
            $this->error("Berkas tidak ditemukan: {$berkas}");
            return self::FAILURE;
        }
        $alias = [];
        foreach ((array) $this->option('alias') as $pasangan) {
            [$nama, $empId] = array_map('trim', explode('=', $pasangan, 2) + [1 => '']);
            $alias[$nama] = $empId;
        }

        try {
            $hasil = LaporanUpahBpjsTk::impor($berkas, $alias, (bool) $this->option('simpan'), null);
        } catch (\Throwable $exception) {
            $this->error('Gagal: ' . $exception->getMessage());
            return self::FAILURE;
        }

        $this->table(['Baris', 'Nama di Excel', 'ID', 'Nama di SIAP', 'Cocok lewat', 'KPJ · upah', 'Catatan'],
            array_map(fn($row) => [$row['baris'], $row['nama'], $row['emp_id'], $row['emp_name'], $row['cara'], $row['ringkas'], $row['catatan']], $hasil['cocok']));
        $this->info(count($hasil['cocok']) . ' karyawan cocok' . ($hasil['npp'] !== '' ? " · NPP {$hasil['npp']}" : '') . '.');
        if ($hasil['lewat'] !== []) {
            $this->warn(count($hasil['lewat']) . ' baris TIDAK diimpor — petakan dengan --alias="Nama di Excel=EMP_ID" bila memang orangnya:');
            $this->table(['Baris', 'Nama di Excel', 'KPJ · upah', 'Alasan', 'Saran'], $hasil['lewat']);
        }
        if (!$this->option('simpan')) {
            $this->comment('Pratinjau saja. Tambah --simpan untuk menulis ke parameter gaji & master karyawan.');
            return self::SUCCESS;
        }
        $this->info("{$hasil['disimpan']} karyawan disimpan (KPJ, kode TK, ID pegawai, upah lapor; NIK & tanggal lahir diisi bila kosong)."
            . ($hasil['npp'] !== '' ? " NPP {$hasil['npp']} disimpan di Ketentuan BPJS." : ''));

        return self::SUCCESS;
    }
}
