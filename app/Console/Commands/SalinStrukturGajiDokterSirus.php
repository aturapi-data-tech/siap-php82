<?php

namespace App\Console\Commands;

use App\Support\GajiDokterSirus;
use App\Support\GajiDokterStruktur;
use Illuminate\Console\Command;

/**
 * Salin struktur gaji dokter dari SIRUS lewat terminal — sama persis dengan tombol di Gaji Dokter → Struktur Gaji.
 * Bawaan membaca tabel RSMST_DOCTORS SIRUS langsung di Oracle (satu instance, beda skema); --api memakai endpoint SIRUS (butuh SIAP_API_*).
 * Master dokter ikut disamakan (dokter baru, status aktif/nonaktif). Insert bila belum ada, update bila sudah ada; aman diulang,
 * struktur yang sudah disetel di SIAP tidak ditimpa.
 *
 *   php artisan gaji:salin-struktur-sirus          → dari database SIRUS
 *   php artisan gaji:salin-struktur-sirus --api    → dari API SIRUS
 */
class SalinStrukturGajiDokterSirus extends Command
{
    protected $signature = 'gaji:salin-struktur-sirus {--api : Lewat API SIRUS, bukan baca tabel langsung}';

    protected $description = 'Salin struktur gaji dokter + status aktif dari SIRUS (sama dengan tombol Salin/Tarik di tab Struktur Gaji)';

    public function handle(): int
    {
        try {
            if ($this->option('api')) {
                if (!GajiDokterSirus::aktif()) {
                    $this->error('API SIRUS belum diatur: isi SIAP_API_URL / SIAP_API_USER / SIAP_API_PASSWORD di .env lalu php artisan config:clear.');
                    return self::FAILURE;
                }
                $hasilSalinStruktur = GajiDokterStruktur::tarikDariSirus(null);
                $sumber = 'API SIRUS';
            } else {
                if (!GajiDokterStruktur::databaseSirusTersedia()) {
                    $this->error('Koneksi database SIAP belum bisa membaca tabel dokter SIRUS (skema ' . config('services.sirus.schema', 'SIRUS') . '). Periksa hak user SIAP, atau pakai --api.');
                    return self::FAILURE;
                }
                $hasilSalinStruktur = GajiDokterStruktur::salinDariDatabaseSirus(null);
                $sumber = 'database SIRUS';
            }
        } catch (\Throwable $exception) {
            $this->error('Gagal: ' . $exception->getMessage());
            return self::FAILURE;
        }

        $this->info("Struktur {$hasilSalinStruktur['ditarik']} dokter disalin dari {$sumber}, status aktif/nonaktif disamakan.");
        if ($hasilSalinStruktur['dokterBaru'] > 0) {
            $this->line("  {$hasilSalinStruktur['dokterBaru']} dokter baru masuk master (petakan ke karyawan di tab Pemetaan Dokter).");
        }
        if ($hasilSalinStruktur['dilewati'] > 0) {
            $this->line("  {$hasilSalinStruktur['dilewati']} dilewati (sudah disetel di SIAP).");
        }

        return self::SUCCESS;
    }
}
