<?php

namespace App\Console\Commands;

use App\Support\StrukturDatabase;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * Bangkitkan ulang dokumentasi struktur database dari database yang sedang dipakai:
 *   database/sql/siap-struktur.sql       — DDL siap eksekusi (sequence, tabel, index, FK, trigger, data migrations)
 *   public/images/panduan/erd-siap.svg   — diagram relasi antar tabel (graphviz `dot`)
 * Jalankan setiap kali ada migrasi baru, lalu commit kedua berkas.
 */
class StrukturDb extends Command
{
    protected $signature = 'siap:struktur-db';

    protected $description = 'Buat ulang SQL struktur database & gambar relasi tabel (ERD) untuk Panduan Struktur Database';

    public function handle(): int
    {
        $berkasSql = base_path(StrukturDatabase::BERKAS_SQL);
        @mkdir(dirname($berkasSql), 0775, true);
        file_put_contents($berkasSql, StrukturDatabase::sql());
        $this->info('SQL  : ' . StrukturDatabase::BERKAS_SQL . ' (' . number_format(filesize($berkasSql)) . ' byte)');

        $berkasSvg = public_path(StrukturDatabase::BERKAS_SVG);
        @mkdir(dirname($berkasSvg), 0775, true);
        $proses = new Process(['dot', '-Tsvg', '-o', $berkasSvg]);
        $proses->setInput(StrukturDatabase::dot());
        $proses->run();
        if (!$proses->isSuccessful()) {
            $this->error('Gagal membuat ERD (graphviz `dot` terpasang?): ' . $proses->getErrorOutput());

            return self::FAILURE;
        }
        $this->info('ERD  : public/' . StrukturDatabase::BERKAS_SVG . ' (' . number_format(filesize($berkasSvg)) . ' byte)');

        return self::SUCCESS;
    }
}
