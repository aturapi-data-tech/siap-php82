<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Karyawan contoh — mengikuti contoh lapangan "Jadwal Masuk Kerja Bulan Oktober 2026"
 * (Klinik Madinah Pratama). Hanya nama yang tersedia di contoh; jenis kelamin, NIK, dll.
 * sengaja dikosongkan — lengkapi lewat Master Karyawan.
 *
 * Idempotent: emp_id yang sudah ada dilewati.
 * Jalankan: php artisan db:seed --class=DummyKaryawanSeeder
 */
class DummyKaryawanSeeder extends Seeder
{
    /** emp_id => [nama, shift default (kolom P/S) = shift terbanyak di jadwal Oktober 2026] */
    public const KARYAWAN = [
        'KMP-001' => ['HARNITA', 'PAGI'],
        'KMP-002' => ['ENDAH SRIRAHAYU NINGSIH', 'SORE'],
        'KMP-003' => ['ANANDA AGUSTIN MARGARETA', 'SORE'],
        'KMP-004' => ['IRMA ARISTA PRATIWI', 'PAGI'],
        'KMP-005' => ['ULFA MARATUSH SHOLEHAH', 'SORE'],
        'KMP-006' => ['NEVI PUSPITA LESTARI', 'PAGI'],
        'KMP-007' => ['NUR WAKHID BURHANUDIN', 'SORE'],
        'KMP-008' => ['IRFAN HAMDANA YUFI', 'SORE'],
        'KMP-009' => ['SELVIOLETA JULZABELLINA', 'SORE'],
    ];

    public function run(): void
    {
        $baru = 0;
        foreach (self::KARYAWAN as $id => [$nama, $shiftDefault]) {
            if (DB::table('siapmst_employers')->where('emp_id', $id)->exists()) {
                continue;
            }
            DB::table('siapmst_employers')->insert([
                'emp_id' => $id, 'emp_name' => $nama, 'emp_is_nakes' => '0', 'active_status' => '1',
                'emp_shift_default' => DB::table('siapmst_shifts')->where('shift_id', $shiftDefault)->exists() ? $shiftDefault : null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $baru++;
        }

        $this->command?->info("Karyawan contoh ditambahkan: {$baru}");
    }
}
