<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Unit kerja contoh + pembagian karyawan contoh (DummyKaryawanSeeder) ke unitnya.
 * Rawat Inap mendapat shift khusus unit: Malam (M) 21:00–07:00 lintas hari —
 * unit lain tetap memakai shift semua unit (P / S).
 *
 * Idempotent: unit/shift yang sudah ada dilewati; karyawan yang sudah ber-unit tidak diubah.
 * Jalankan: php artisan db:seed --class=DummyUnitKerjaSeeder
 */
class DummyUnitKerjaSeeder extends Seeder
{
    public const UNIT = [
        'DAFTAR' => 'Pendaftaran',
        'APOTEK' => 'Apotek',
        'POLI'   => 'Poli',
        'RANAP'  => 'Ruang Rawat Inap',
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
        if (!DB::table('siapmst_shifts')->where('shift_id', 'MALAM-RI')->exists()) {
            DB::table('siapmst_shifts')->insert([
                'shift_id' => 'MALAM-RI', 'shift_name' => 'Shift Malam Rawat Inap',
                'jam_masuk' => '21:00', 'jam_pulang' => '07:00', 'toleransi_menit' => 10, 'lintas_hari' => '1',
                'shift_kode' => 'M', 'unit_id' => 'RANAP', 'active_status' => '1',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $shiftBaru++;
        }

        $karyawanDiatur = 0;
        foreach (self::KARYAWAN_UNIT as $empId => $unitId) {
            $karyawanDiatur += DB::table('siapmst_employers')->where('emp_id', $empId)->whereNull('emp_unit_id')
                ->update(['emp_unit_id' => $unitId, 'updated_at' => now()]);
        }

        $this->command?->info("Unit ditambahkan: {$unitBaru}, shift unit: {$shiftBaru}, karyawan diberi unit: {$karyawanDiatur}");
    }
}
