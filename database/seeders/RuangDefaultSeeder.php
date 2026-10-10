<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Ruang / unit awal RS (data master, bukan dummy) — bisa diubah di menu Master Ruang.
 * Idempotent: ruang_id yang sudah ada dilewati.
 * Jalankan: php artisan db:seed --class=RuangDefaultSeeder
 */
class RuangDefaultSeeder extends Seeder
{
    public function run(): void
    {
        $ruangs = [
            'IGD'   => 'INSTALASI GAWAT DARURAT',
            'ICU'   => 'INTENSIVE CARE UNIT',
            'OK'    => 'KAMAR OPERASI',
            'VK'    => 'KAMAR BERSALIN',
            'PERI'  => 'PERINATOLOGI',
            'RI'    => 'RAWAT INAP',
            'RJ'    => 'POLIKLINIK RAWAT JALAN',
            'LAB'   => 'LABORATORIUM',
            'RAD'   => 'RADIOLOGI',
            'APT'   => 'INSTALASI FARMASI',
            'GZ'    => 'INSTALASI GIZI',
            'IPSRS' => 'IPSRS',
            'TU'    => 'TATA USAHA',
        ];

        $baru = 0;
        foreach ($ruangs as $id => $nama) {
            if (DB::table('siapmst_ruangs')->where('ruang_id', $id)->exists()) {
                continue;
            }
            DB::table('siapmst_ruangs')->insert([
                'ruang_id' => $id, 'ruang_name' => $nama, 'active_status' => '1',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $baru++;
        }

        $this->command?->info("Ruang ditambahkan: {$baru}");
    }
}
