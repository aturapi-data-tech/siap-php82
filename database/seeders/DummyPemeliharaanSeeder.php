<?php

namespace Database\Seeders;

use App\Support\Absensi;
use App\Support\Pemeliharaan;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Checklist perawatan alat contoh: bulan lalu + bulan ini s/d KEMARIN (hari ini dibiarkan
 * kosong untuk dicoba). Alat IGD dijadikan harian (format checklist IGD RS), lainnya mingguan.
 * ±95% Baik, ±4% Perlu Perbaikan, ±1% Rusak (dengan keterangan). Acak ber-seed tetap.
 * Butuh DummyAlatSeeder. Idempotent: (alat, tanggal) yang sudah ada dilewati.
 * Jalankan: php artisan db:seed --class=DummyPemeliharaanSeeder
 */
class DummyPemeliharaanSeeder extends Seeder
{
    private const KETERANGAN = [
        'P' => ['Kabel power longgar, dilaporkan IPSRS', 'Baterai cepat habis', 'Layar redup', 'Selang aus, perlu ganti'],
        'R' => ['Tidak menyala, dikirim ke IPSRS', 'Error saat dinyalakan, alat ditarik'],
    ];

    public function run(): void
    {
        mt_srand(20261008);

        DB::table('siapmst_assets')->where('ruang_id', 'IGD')->where('asset_jenis', 'M')->update(['pm_frekuensi' => 'H']);

        $alat = DB::table('siapmst_assets')->where('active_status', '1')->whereIn('pm_frekuensi', ['H', 'M'])->get();
        $petugas = DB::table('users')->orderBy('id')->value('id');
        $kemarin = now()->subDay()->startOfDay();
        $baru = 0;

        foreach ([now()->subMonthNoOverflow()->startOfMonth(), now()->startOfMonth()] as $awal) {
            foreach ($alat as $a) {
                $slot = Pemeliharaan::jumlahSlot($awal, $a->pm_frekuensi);
                for ($s = 1; $s <= $slot; $s++) {
                    $tgl = $a->pm_frekuensi === 'H'
                        ? $awal->copy()->day($s)
                        : $awal->copy()->day(min(Pemeliharaan::rentangMinggu($awal, $s)[0] + mt_rand(0, 2), $awal->daysInMonth));
                    if ($tgl->gt($kemarin)) {
                        continue;
                    }
                    if (DB::table('siaptxn_pemeliharaans')->where('asset_id', $a->asset_id)->where('pm_tanggal', Absensi::tanggalSql($tgl))->exists()) {
                        continue;
                    }

                    $u = mt_rand(1, 100);
                    $hasil = $u <= 95 ? 'B' : ($u <= 99 ? 'P' : 'R');
                    $ket = $hasil === 'B' ? null : self::KETERANGAN[$hasil][mt_rand(0, count(self::KETERANGAN[$hasil]) - 1)];

                    DB::table('siaptxn_pemeliharaans')->insert([
                        'asset_id' => $a->asset_id, 'pm_tanggal' => Absensi::tanggalSql($tgl), 'pm_hasil' => $hasil,
                        'keterangan' => $ket, 'petugas_id' => $petugas, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $baru++;
                }
            }
        }

        $this->command?->info("Checklist perawatan contoh ditambahkan: {$baru} baris");
    }
}
