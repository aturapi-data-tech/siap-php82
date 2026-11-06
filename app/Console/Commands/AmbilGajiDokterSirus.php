<?php

namespace App\Console\Commands;

use App\Support\GajiDokterSirus;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Tarik snapshot gaji dokter dari SIRUS lewat terminal — untuk sinkronisasi awal di server (banyak bulan sekaligus)
 * atau cron. Sama persis dengan tombol "Ambil dari SIRUS" di halaman Gaji Dokter: aman diulang, versi baru hanya
 * bila angka SIRUS berubah, tidak menulis apa pun ke SIRUS.
 *
 *   php artisan gaji:ambil-sirus                      → bulan jasa lalu
 *   php artisan gaji:ambil-sirus 2026-07              → satu bulan jasa
 *   php artisan gaji:ambil-sirus 2026-01 --sampai=2026-10   → rentang bulan jasa
 *   php artisan gaji:ambil-sirus --uji                → hanya uji sambungan
 */
class AmbilGajiDokterSirus extends Command
{
    protected $signature = 'gaji:ambil-sirus {periode? : Bulan jasa YYYY-MM (kosong = bulan lalu)} {--sampai= : Bulan jasa akhir YYYY-MM (rentang)} {--uji : Hanya uji sambungan}';

    protected $description = 'Tarik snapshot slip gaji dokter dari SIRUS per bulan jasa (sama dengan tombol Ambil dari SIRUS)';

    public function handle(): int
    {
        if (!GajiDokterSirus::aktif()) {
            $this->error('API SIRUS belum diatur: isi SIAP_API_URL / SIAP_API_USER / SIAP_API_PASSWORD di .env lalu php artisan config:clear.');
            return self::FAILURE;
        }
        $uji = GajiDokterSirus::ujiSambungan();
        $this->line(($uji['ok'] ? '<info>✓</info> ' : '<error>✗</error> ') . $uji['pesan']);
        if (!$uji['ok']) {
            return self::FAILURE;
        }
        if ($this->option('uji')) {
            return self::SUCCESS;
        }

        $awal = (string) ($this->argument('periode') ?: now()->subMonthNoOverflow()->format('Y-m'));
        $akhir = (string) ($this->option('sampai') ?: $awal);
        if (!GajiDokterSirus::periodeValid($awal) || !GajiDokterSirus::periodeValid($akhir) || $akhir < $awal) {
            $this->error('Periode harus YYYY-MM dan --sampai tidak boleh sebelum periode awal.');
            return self::FAILURE;
        }

        $gagal = 0;
        for ($p = Carbon::createFromFormat('!Y-m', $awal); $p->format('Y-m') <= $akhir; $p->addMonthNoOverflow()) {
            $periode = $p->format('Y-m');
            $mulai = microtime(true);
            try {
                $h = GajiDokterSirus::ambil($periode, null);
            } catch (\Throwable $e) {
                $this->error("{$periode}: {$e->getMessage()}");
                $gagal++;
                continue;
            }
            $detik = round(microtime(true) - $mulai, 1);
            $this->line(sprintf('<info>%s</info>: %d slip — %d baru, %d berubah, %d sama, %s baris lampiran (%ss)%s',
                $periode, $h['jumlah'], $h['baru'], $h['berubah'], $h['sama'], number_format($h['lampiran'], 0, ',', '.'), $detik,
                $h['tanpaMap'] !== [] ? ' — ' . count($h['tanpaMap']) . ' dokter belum dipetakan' : ''));
            if ($h['jumlah'] === 0) {
                $this->comment("   SIRUS belum punya slip gaji dokter bulan jasa {$periode}.");
            }
        }
        if ($gagal === 0) {
            $this->comment('Lengkapi pemetaan dokter → karyawan di Gaji Dokter (SIRUS) → tab Pemetaan Dokter.');
        }

        return $gagal === 0 ? self::SUCCESS : self::FAILURE;
    }
}
