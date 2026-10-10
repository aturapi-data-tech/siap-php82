<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Shift awal (data master, bukan dummy) — mengikuti jam kerja Klinik Madinah Pratama:
 * Pagi 07:00–12:00 (P), Sore 17:00–21:00 (S).
 * Bisa diubah di menu Master Shift.
 * Idempotent: shift_id yang sudah ada dilewati.
 * Jalankan: php artisan db:seed --class=ShiftDefaultSeeder
 */
class ShiftDefaultSeeder extends Seeder
{
    public function run(): void
    {
        $shifts = [
            // id,      nama,        masuk,   pulang,  toleransi, lintas hari, kode
            ['PAGI',  'Shift Pagi',  '07:00', '12:00', 10, '0', 'P'],
            ['SORE',  'Shift Sore',  '17:00', '21:00', 10, '0', 'S'],
        ];

        $baru = 0;
        foreach ($shifts as [$id, $nama, $masuk, $pulang, $toleransi, $lintas, $kode]) {
            if (DB::table('siapmst_shifts')->where('shift_id', $id)->exists()) {
                continue;
            }
            DB::table('siapmst_shifts')->insert([
                'shift_id'        => $id,
                'shift_name'      => $nama,
                'jam_masuk'       => $masuk,
                'jam_pulang'      => $pulang,
                'toleransi_menit' => $toleransi,
                'lintas_hari'     => $lintas,
                'shift_kode'      => $kode,
                'active_status'   => '1',
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
            $baru++;
        }

        $this->command?->info("Shift ditambahkan: {$baru}");
    }
}
