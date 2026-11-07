<?php

namespace App\Console\Commands;

use App\Support\GajiDokterSirus;
use App\Support\GajiDokterStruktur;
use Illuminate\Console\Command;

/**
 * Salin struktur gaji dokter + master dokter (status aktif/nonaktif) dari SIRUS ke SIAP lewat terminal — sama dengan tombol
 * di Gaji Dokter → Struktur Gaji. Insert bila belum ada, update bila sudah ada; aman diulang, struktur yang sudah disetel di
 * SIAP tidak ditimpa (saat mode SIAP).
 *
 * Bawaan memakai BERKAS DATA yang ditanam di repo (database/data/struktur-gaji-dokter-sirus.json) — tidak membaca skema
 * SIRUS, jadi jalan di server mana pun. Berkas itu dibuat/diperbarui dengan --ekspor di komputer yang bisa membaca tabel SIRUS.
 *
 *   php artisan gaji:salin-struktur-sirus             → impor dari berkas data (server)
 *   php artisan gaji:salin-struktur-sirus --ekspor    → baca tabel SIRUS → tulis berkas data (dev), lalu commit berkasnya
 *   php artisan gaji:salin-struktur-sirus --database  → baca tabel SIRUS langsung (bila user Oracle SIAP punya hak baca)
 *   php artisan gaji:salin-struktur-sirus --api       → lewat API SIRUS (butuh SIAP_API_*)
 */
class SalinStrukturGajiDokterSirus extends Command
{
    protected $signature = 'gaji:salin-struktur-sirus
        {--ekspor : Baca tabel SIRUS lalu tulis berkas data (jalankan di komputer dev yang bisa membaca SIRUS)}
        {--database : Baca tabel SIRUS langsung, bukan dari berkas data}
        {--api : Lewat API SIRUS, bukan dari berkas data}';

    protected $description = 'Salin struktur gaji dokter + status aktif dari SIRUS ke SIAP (bawaan: dari berkas data yang ditanam di repo)';

    public function handle(): int
    {
        try {
            if ($this->option('ekspor')) {
                if (!GajiDokterStruktur::databaseSirusTersedia()) {
                    $this->error('Komputer ini tidak bisa membaca tabel dokter SIRUS — --ekspor dijalankan di komputer dev yang satu Oracle dengan SIRUS.');
                    return self::FAILURE;
                }
                $hasilEkspor = GajiDokterStruktur::eksporKeBerkas();
                $this->info("{$hasilEkspor['jumlah']} dokter ditulis ke {$hasilEkspor['berkas']}. Commit berkas itu, lalu di server: php artisan gaji:salin-struktur-sirus");
                return self::SUCCESS;
            }
            if ($this->option('api')) {
                if (!GajiDokterSirus::aktif()) {
                    $this->error('API SIRUS belum diatur: isi SIAP_API_URL / SIAP_API_USER / SIAP_API_PASSWORD di .env lalu php artisan config:clear.');
                    return self::FAILURE;
                }
                $hasilSalinStruktur = GajiDokterStruktur::tarikDariSirus(null);
                $sumber = 'API SIRUS';
            } elseif ($this->option('database')) {
                if (!GajiDokterStruktur::databaseSirusTersedia()) {
                    $this->error('Koneksi database SIAP tidak bisa membaca tabel dokter SIRUS. Pakai berkas data (tanpa opsi) atau --api.');
                    return self::FAILURE;
                }
                $hasilSalinStruktur = GajiDokterStruktur::salinDariDatabaseSirus(null);
                $sumber = 'database SIRUS';
            } else {
                $hasilSalinStruktur = GajiDokterStruktur::imporDariBerkas(null);
                $sumber = 'berkas data (ekspor SIRUS ' . ($hasilSalinStruktur['diekspor_pada'] ?? '?') . ')';
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
