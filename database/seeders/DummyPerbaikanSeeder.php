<?php

namespace Database\Seeders;

use App\Support\Perbaikan;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Perbaikan alat contoh — semua status muncul (Diserahkan kembali internal & vendor,
 * Selesai, Di vendor, Ditangani, Dilaporkan, Tidak dapat diperbaiki). Waktu RELATIF dari
 * sekarang. Sebagian dari temuan P/R checklist pemeliharaan (pm_id), sisanya laporan langsung.
 * Kondisi alat di inventaris disesuaikan dengan status (aturan sama dengan form).
 * Butuh DummyAlatSeeder & DummyPemeliharaanSeeder. Idempotent: alat yang sudah punya
 * perbaikan dilewati.
 * Jalankan: php artisan db:seed --class=DummyPerbaikanSeeder
 */
class DummyPerbaikanSeeder extends Seeder
{
    public function run(): void
    {
        $sekarang = now()->startOfMinute();
        $user = DB::table('users')->orderBy('id')->first(['id', 'name']);

        // asset, status, hari lalu lapor, kerusakan, respon(mnt), teknisi, pelaksana, vendor, SR, analisa, suku cadang, biaya, lama(hari), keterangan
        $data = [
            ['IGD.EKG.01/2022', 'K', 20, 'Rekaman EKG tidak keluar, kabel lead putus', 10, 'Kukuh R.', 'I', null, null, 'Kabel lead patient putus di konektor', 'Ganti kabel lead 10 channel', 450000, 2, null],
            ['ICU.IP.01/2023', 'V', 25, 'Hasil kalibrasi tidak laik — akurasi flow menyimpang', 12, 'Kukuh R.', 'V', 'PT Terumo Indonesia', 'SR-TRM-2610-018', null, null, 0, null, null],
            ['IGD.SC.01/2020', 'P', 3, 'Daya hisap lemah, bunyi kasar', 25, 'Kukuh R.', 'I', null, null, null, null, 0, null, null],
            ['LAB.CEN.01/2019', 'S', 6, 'Getaran berlebih saat putaran tinggi', 8, 'Kukuh R.', 'V', 'PT Hettich Indonesia', 'SR-HT-2610-002', 'Bearing motor aus', 'Ganti bearing motor', 1250000, 4, null],
            ['TU.PRN.01/2020', 'X', 40, 'Printer tidak menarik kertas, head rusak', 15, 'Kukuh R.', 'I', null, null, 'Head printer rusak total, suku cadang tidak tersedia', null, 0, 3, 'Usul penggantian unit baru (anggaran 2027)'],
            ['RJ.TB.01/2023', 'B', 0, 'Angka timbangan tidak stabil', null, null, null, null, null, null, null, 0, null, null],
        ];

        $baru = 0;
        foreach ($data as [$asset, $status, $hariLalu, $rusak, $respon, $teknisi, $pelaksana, $vendor, $sr, $analisa, $suku, $biaya, $lama, $ket]) {
            $alat = DB::table('siapmst_assets')->where('asset_id', $asset)->first(['asset_id', 'ruang_id']);
            if (!$alat || DB::table('siaptxn_perbaikans')->where('asset_id', $asset)->exists()) {
                continue;
            }

            $minta = $sekarang->copy()->subDays($hariLalu)->setTime(8, 30)->addMinutes(($baru * 37) % 300);
            if ($minta->gt($sekarang)) {
                $minta = $sekarang->copy()->subHour();
            }
            $selesai = $lama !== null ? $minta->copy()->addDays($lama)->setTime(14, 0) : null;

            DB::table('siaptxn_perbaikans')->insert([
                'pb_no' => Perbaikan::nomorBaru($minta), 'asset_id' => $asset, 'ruang_id' => $alat->ruang_id, 'pb_status' => $status,
                'tgl_permintaan' => $minta, 'pelapor' => 'Perawat ' . $alat->ruang_id, 'kerusakan' => $rusak,
                'tgl_respon' => $respon !== null ? $minta->copy()->addMinutes($respon) : null, 'teknisi' => $teknisi,
                'analisa' => $analisa, 'pb_pelaksana' => $pelaksana, 'vendor' => $vendor, 'no_service_report' => $sr,
                'suku_cadang' => $suku, 'biaya' => $biaya,
                'tgl_selesai' => in_array($status, ['S', 'K', 'X'], true) ? $selesai : null,
                'tgl_diserahkan' => $status === 'K' ? $selesai->copy()->addHour() : null,
                'penerima' => $status === 'K' ? 'Karu ' . $alat->ruang_id : null,
                'keterangan' => $ket, 'updated_by' => $user?->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $baru++;

            $kondisi = match ($status) { 'K' => 'B', 'X' => 'RB', 'V' => 'RB', default => 'RR' };
            DB::table('siapmst_assets')->where('asset_id', $asset)->update(['kondisi' => $kondisi, 'updated_at' => now()]);
        }

        // Dua temuan R checklist pemeliharaan bulan lalu → dilaporkan & sudah selesai (dari checklist).
        $temuan = DB::table('siaptxn_pemeliharaans')->where('pm_hasil', 'R')->orderBy('pm_tanggal')->limit(2)->get();
        foreach ($temuan as $pm) {
            if (DB::table('siaptxn_perbaikans')->where('pm_id', $pm->pm_id)->exists()
                || ($aktif = Perbaikan::aktifUntuk($pm->asset_id))) {
                continue;
            }
            $alat = DB::table('siapmst_assets')->where('asset_id', $pm->asset_id)->first(['ruang_id']);
            $minta = Carbon::parse($pm->pm_tanggal)->setTime(9, 15);
            DB::table('siaptxn_perbaikans')->insert([
                'pb_no' => Perbaikan::nomorBaru($minta), 'asset_id' => $pm->asset_id, 'ruang_id' => $alat->ruang_id, 'pb_status' => 'K',
                'tgl_permintaan' => $minta, 'pelapor' => $user?->name, 'kerusakan' => 'Temuan checklist pemeliharaan: Rusak — ' . ($pm->keterangan ?? '-'),
                'tgl_respon' => $minta->copy()->addMinutes(11), 'teknisi' => 'Kukuh R.', 'analisa' => 'Konektor power aus', 'pb_pelaksana' => 'I',
                'suku_cadang' => 'Ganti konektor power', 'biaya' => 75000,
                'tgl_selesai' => $minta->copy()->addDay()->setTime(10, 0), 'tgl_diserahkan' => $minta->copy()->addDay()->setTime(11, 0),
                'penerima' => 'Karu ' . $alat->ruang_id, 'pm_id' => $pm->pm_id, 'updated_by' => $user?->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $baru++;
        }

        $this->command?->info("Perbaikan contoh ditambahkan: {$baru}");
    }
}
