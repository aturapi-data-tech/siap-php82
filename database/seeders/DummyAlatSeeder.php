<?php

namespace Database\Seeders;

use App\Support\Kalibrasi;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Inventaris alat contoh + riwayat kalibrasi (untuk coba-coba).
 *
 * Tanggal kalibrasi dihitung RELATIF dari hari ini supaya semua status selalu muncul:
 * Berlaku, Segera (≤60 hari), Kedaluwarsa, Tidak Laik, Belum pernah.
 * Nomor sertifikat berawalan DUMMY — bukan sertifikat asli.
 * Butuh RuangDefaultSeeder lebih dulu. Idempotent: asset_id yang sudah ada dilewati.
 * Jalankan: php artisan db:seed --class=DummyAlatSeeder
 */
class DummyAlatSeeder extends Seeder
{
    public function run(): void
    {
        $hariIni = Carbon::today();
        $ipm  = 'PT Inti Presisi Medica (KAN LK-224-IDN)';
        $bpfk = 'BPFK Surabaya';

        // [kode, nama, jenis, merk, tipe, no seri, ruang, tahun, jml, kondisi, wajib, riwayat]
        // riwayat: [ [hari lalu, pelaksana, hasil], ... ] — berlaku s/d = tanggal + 12 bulan (Laik)
        $alat = [
            ['IGD.SP.01/2023', 'SYRINGE PUMP', 'M', 'MINDRAY', 'Benefusion uSP', 'SM3-52044576', 'IGD', 2023, 1, 'B', '1', [[296, $ipm, 'L']]],
            ['IGD.SP.02/2023', 'SYRINGE PUMP', 'M', 'MINDRAY', 'Benefusion uSP', 'SM3-52044581', 'IGD', 2023, 1, 'B', '1', [[296, $ipm, 'L']]],
            ['IGD.PM.01/2022', 'PATIENT MONITOR', 'M', 'MINDRAY', 'uMEC 10', 'K5130126087', 'IGD', 2022, 1, 'B', '1', [[667, $bpfk, 'L'], [301, $ipm, 'L']]],
            ['IGD.DEF.01/2021', 'DEFIBRILLATOR', 'M', 'ZOLL', 'M Series', 'T05J-112233', 'IGD', 2021, 1, 'B', '1', [[340, $ipm, 'L']]],     // segera
            ['IGD.SC.01/2020', 'SUCTION PUMP', 'M', 'GEA', 'YX-930D', 'GEA-930-7781', 'IGD', 2020, 1, 'RR', '1', [[400, $bpfk, 'L']]],    // kedaluwarsa
            ['IGD.EKG.01/2022', 'ELEKTROKARDIOGRAF (EKG)', 'M', 'FUKUDA DENSHI', 'Cardimax FX-8322', 'FD-8322-0045', 'IGD', 2022, 1, 'B', '1', [[150, $ipm, 'L']]],
            ['IGD.NEB.01/2024', 'NEBULIZER', 'M', 'OMRON', 'NE-C28', 'OM-C28-5521', 'IGD', 2024, 2, 'B', '1', []],                     // belum pernah
            ['ICU.VEN.01/2022', 'VENTILATOR', 'M', 'HAMILTON', 'C1', 'HM-C1-33019', 'ICU', 2022, 1, 'B', '1', [[200, $ipm, 'L']]],
            ['ICU.IP.01/2023', 'INFUSION PUMP', 'M', 'TERUMO', 'TE-135', 'TE135-88120', 'ICU', 2023, 1, 'B', '1', [[30, $ipm, 'T']]],   // tidak laik
            ['ICU.PM.01/2023', 'PATIENT MONITOR', 'M', 'PHILIPS', 'IntelliVue MX450', 'PH-MX450-221', 'ICU', 2023, 1, 'B', '1', [[355, $ipm, 'L']]], // segera
            ['OK.ESU.01/2021', 'ELECTROSURGICAL UNIT', 'M', 'VALLEYLAB', 'Force FX', 'VL-FFX-1092', 'OK', 2021, 1, 'B', '1', [[90, $bpfk, 'L']]],
            ['OK.AN.01/2021', 'MESIN ANESTESI', 'M', 'DRAEGER', 'Fabius Plus', 'DR-FAB-55120', 'OK', 2021, 1, 'B', '1', [[410, $bpfk, 'L']]], // kedaluwarsa
            ['VK.CTG.01/2022', 'CARDIOTOCOGRAPH (CTG)', 'M', 'BIONET', 'FC 1400', 'BN-1400-3381', 'VK', 2022, 1, 'B', '1', [[120, $ipm, 'L']]],
            ['PERI.INK.01/2020', 'INKUBATOR BAYI', 'M', 'ATOM', 'V-2100G', 'AT-2100-0918', 'PERI', 2020, 1, 'B', '1', [[60, $ipm, 'L']]],
            ['RI.TD.01/2024', 'TENSIMETER DIGITAL', 'M', 'OMRON', 'HBP-1120', 'OM-1120-7720', 'RI', 2024, 4, 'B', '1', [[180, $ipm, 'L']]],
            ['RJ.TB.01/2023', 'TIMBANGAN BAYI', 'M', 'GEA', 'BB-01', 'GEA-BB01-2210', 'RJ', 2023, 1, 'B', '1', [[250, $bpfk, 'L']]],
            ['LAB.HEM.01/2022', 'HEMATOLOGY ANALYZER', 'M', 'MINDRAY', 'BC-5150', 'MR-BC5150-912', 'LAB', 2022, 1, 'B', '1', [[100, $ipm, 'L']]],
            ['LAB.CEN.01/2019', 'CENTRIFUGE', 'M', 'HETTICH', 'EBA 200', 'HT-EBA200-445', 'LAB', 2019, 1, 'B', '1', [[320, $ipm, 'L']]],
            ['RAD.XR.01/2018', 'X-RAY MOBILE', 'M', 'SHIMADZU', 'MobileDaRt Evolution', 'SH-MDE-0071', 'RAD', 2018, 1, 'B', '1', [[45, $bpfk, 'L']]],
            ['APT.TG.01/2015', 'TIMBANGAN GRAM', 'M', 'OHAUS', 'Pioneer PX', 'OH-PX-2015-01', 'APT', 2015, 1, 'B', '1', [[292, $ipm, 'L']]],
            ['APT.KUL.01/2021', 'KULKAS VAKSIN', 'N', 'SHARP', 'SJ-195MD', 'SH-195-88341', 'APT', 2021, 1, 'B', '0', []],
            ['IPSRS.KOM.01/2022', 'KOMPUTER', 'N', 'LENOVO', 'ThinkCentre M70', 'LN-M70-55120', 'IPSRS', 2022, 1, 'B', '0', []],
            ['TU.PRN.01/2020', 'PRINTER', 'N', 'EPSON', 'L3110', 'EP-L3110-9921', 'TU', 2020, 1, 'RB', '0', []],
        ];

        $alatBaru = 0;
        $kalBaru = 0;

        foreach ($alat as [$id, $nama, $jenis, $merk, $tipe, $seri, $ruang, $tahun, $jml, $kondisi, $wajib, $riwayat]) {
            if (DB::table('siapmst_assets')->where('asset_id', $id)->exists()) {
                continue;
            }
            if (!DB::table('siapmst_ruangs')->where('ruang_id', $ruang)->exists()) {
                $this->command?->warn("Ruang {$ruang} belum ada — jalankan RuangDefaultSeeder. {$id} dilewati.");
                continue;
            }

            DB::table('siapmst_assets')->insert([
                'asset_id' => $id, 'asset_name' => $nama, 'asset_jenis' => $jenis, 'merk' => $merk,
                'tipe_model' => $tipe, 'no_seri' => $seri, 'ruang_id' => $ruang, 'tahun_pengadaan' => $tahun,
                'jumlah' => $jml, 'kondisi' => $kondisi, 'wajib_kalibrasi' => $wajib,
                'interval_kalibrasi' => Kalibrasi::INTERVAL_DEFAULT, 'active_status' => '1',
                // Perawatan rutin: IGD harian (format checklist IGD RS), alat medis lain mingguan.
                'pm_frekuensi' => $jenis === 'N' ? '-' : ($ruang === 'IGD' ? 'H' : 'M'),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $alatBaru++;

            foreach ($riwayat as $n => [$hariLalu, $pelaksana, $hasil]) {
                $tgl = $hariIni->copy()->subDays($hariLalu);
                DB::table('siaptxn_kalibrasis')->insert([
                    'asset_id'          => $id,
                    'kal_tanggal'       => $tgl,
                    'kal_pelaksana'     => $pelaksana,
                    'kal_no_sertifikat' => 'DUMMY/' . $tgl->format('Y/m') . '/' . str_replace(['.', '/'], '-', $id) . '-' . ($n + 1),
                    'kal_hasil'         => $hasil,
                    'kal_berlaku_sd'    => $hasil === 'L' ? Kalibrasi::berlakuSdDefault($tgl, Kalibrasi::INTERVAL_DEFAULT) : null,
                    'keterangan'        => $hasil === 'T' ? 'Hasil tidak laik — alat ditarik untuk perbaikan' : null,
                    'created_at'        => now(),
                    'updated_at'        => now(),
                ]);
                $kalBaru++;
            }
        }

        $this->command?->info("Alat contoh ditambahkan: {$alatBaru}, riwayat kalibrasi: {$kalBaru}");
    }
}
