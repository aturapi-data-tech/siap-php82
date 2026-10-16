<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Unit kerja contoh + shift khusus unit + pembagian karyawan contoh ke unitnya.
 *
 * Shift semua unit (P 07–12 / S 17–21, ShiftDefaultSeeder) tetap dipakai Pendaftaran, Apotek, Poli.
 * Shift khusus unit (kode beda supaya tidak bentrok dgn P / S):
 *   - Rawat Inap (perawat, standar) : R1 07–14, R2 14–21, M 21–07
 *   - Gizi (masuk lebih awal, pulang lebih cepat, 2 shift) : G1 06–13, G2 13–20
 *   - Satpam (mulai jam 06 seperti Gizi, 3 shift)          : K1 06–14, K2 14–22, K3 22–06
 *
 * Idempotent: unit/shift/karyawan yang sudah ada dilewati; karyawan yang sudah ber-unit tidak diubah.
 * Jalankan: php artisan db:seed --class=DummyUnitKerjaSeeder
 */
class DummyUnitKerjaSeeder extends Seeder
{
    public const UNIT = [
        'DAFTAR' => 'Pendaftaran',
        'APOTEK' => 'Apotek',
        'POLI'   => 'Poli',
        'RANAP'  => 'Ruang Rawat Inap',
        'GIZI'   => 'Gizi',
        'SATPAM' => 'Satpam',
    ];

    /** shift_id => [unit, nama, masuk, pulang, kode, warna] — lintas hari dihitung dari jam */
    public const SHIFT = [
        'RANAP-1'  => ['RANAP', 'Rawat Inap Pagi', '07:00', '14:00', 'R1', '#bef264'],
        'RANAP-2'  => ['RANAP', 'Rawat Inap Sore', '14:00', '21:00', 'R2', '#5eead4'],
        'MALAM-RI' => ['RANAP', 'Shift Malam Rawat Inap', '21:00', '07:00', 'M', null],
        'GIZI-1'   => ['GIZI', 'Gizi Shift 1', '06:00', '13:00', 'G1', '#fcd34d'],
        'GIZI-2'   => ['GIZI', 'Gizi Shift 2', '13:00', '20:00', 'G2', '#fdba74'],
        'SATPAM-1' => ['SATPAM', 'Satpam Shift 1', '06:00', '14:00', 'K1', '#7dd3fc'],
        'SATPAM-2' => ['SATPAM', 'Satpam Shift 2', '14:00', '22:00', 'K2', '#a5b4fc'],
        'SATPAM-3' => ['SATPAM', 'Satpam Shift 3', '22:00', '06:00', 'K3', '#2563eb'],
    ];

    /** Karyawan contoh tambahan: emp_id => [nama, shift default] (unit lewat KARYAWAN_UNIT) */
    public const KARYAWAN_BARU = [
        'KMP-010' => ['SITI AMINAH', 'GIZI-1'],
        'KMP-011' => ['DEWI KARTIKA', 'GIZI-2'],
        'KMP-012' => ['BAMBANG SUTRISNO', 'SATPAM-1'],
        'KMP-013' => ['AGUS SETIAWAN', 'SATPAM-2'],
        'KMP-014' => ['JOKO PRASETYO', 'SATPAM-3'],
    ];

    /** emp_id => unit_id */
    public const KARYAWAN_UNIT = [
        'KMP-001' => 'DAFTAR',
        'KMP-002' => 'DAFTAR',
        'KMP-003' => 'APOTEK',
        'KMP-004' => 'APOTEK',
        'KMP-005' => 'POLI',
        'KMP-006' => 'POLI',
        'KMP-007' => 'RANAP',
        'KMP-008' => 'RANAP',
        'KMP-009' => 'RANAP',
        'KMP-010' => 'GIZI',
        'KMP-011' => 'GIZI',
        'KMP-012' => 'SATPAM',
        'KMP-013' => 'SATPAM',
        'KMP-014' => 'SATPAM',
    ];

    public function run(): void
    {
        $unitBaru = 0;
        foreach (self::UNIT as $unitId => $namaUnit) {
            if (DB::table('siapmst_unit_kerjas')->where('unit_id', $unitId)->exists()) {
                continue;
            }
            DB::table('siapmst_unit_kerjas')->insert([
                'unit_id' => $unitId, 'unit_name' => $namaUnit, 'active_status' => '1',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $unitBaru++;
        }

        $shiftBaru = 0;
        foreach (self::SHIFT as $shiftId => [$unitId, $nama, $masuk, $pulang, $kode, $warna]) {
            if (DB::table('siapmst_shifts')->where('shift_id', $shiftId)->exists()) {
                continue;
            }
            DB::table('siapmst_shifts')->insert([
                'shift_id' => $shiftId, 'shift_name' => $nama, 'jam_masuk' => $masuk, 'jam_pulang' => $pulang,
                'toleransi_menit' => 10, 'lintas_hari' => $pulang <= $masuk ? '1' : '0',
                'shift_kode' => $kode, 'shift_warna' => $warna, 'unit_id' => $unitId, 'active_status' => '1',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $shiftBaru++;
        }

        $lokasiId = DB::table('siapmst_employers')->whereNotNull('emp_lokasi_id')->value('emp_lokasi_id');
        $karyawanBaru = 0;
        foreach (self::KARYAWAN_BARU as $empId => [$nama, $shiftDefault]) {
            if (DB::table('siapmst_employers')->where('emp_id', $empId)->exists()) {
                continue;
            }
            DB::table('siapmst_employers')->insert([
                'emp_id' => $empId, 'emp_name' => $nama, 'emp_is_nakes' => '0', 'active_status' => '1',
                'emp_shift_default' => $shiftDefault, 'emp_lokasi_id' => $lokasiId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $karyawanBaru++;
        }

        $karyawanDiatur = 0;
        foreach (self::KARYAWAN_UNIT as $empId => $unitId) {
            $karyawanDiatur += DB::table('siapmst_employers')->where('emp_id', $empId)->whereNull('emp_unit_id')
                ->update(['emp_unit_id' => $unitId, 'updated_at' => now()]);
        }

        // Perawat memakai jam standar Rawat Inap (07–14 / 14–21), bukan jam klinik P / S.
        $perawatDiatur = DB::table('siapmst_employers')->where('emp_unit_id', 'RANAP')->where('emp_shift_default', 'PAGI')
            ->update(['emp_shift_default' => 'RANAP-1', 'updated_at' => now()])
            + DB::table('siapmst_employers')->where('emp_unit_id', 'RANAP')->where('emp_shift_default', 'SORE')
            ->update(['emp_shift_default' => 'RANAP-2', 'updated_at' => now()]);

        $this->command?->info("Unit: +{$unitBaru}, shift unit: +{$shiftBaru}, karyawan: +{$karyawanBaru}, "
            . "diberi unit: {$karyawanDiatur}, shift default perawat: {$perawatDiatur}");
    }
}
