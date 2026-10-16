<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Shift fleksibel (mis. Poli): jam masuk & pulang mengikuti praktik dokter, bukan jam tetap.
 * shift_fleksibel '1' → jam_masuk–jam_pulang hanya JENDELA absen (tanggal kerja & batas absen pulang);
 * tidak ada terlambat, pulang cepat, maupun lembur otomatis. Karyawan unit tsb boleh absen tanpa jadwal.
 * Idempotent: kolom yang sudah ada dilewati.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!$this->kolomAda()) {
            DB::statement("ALTER TABLE siapmst_shifts ADD (shift_fleksibel VARCHAR2(1) DEFAULT '0' NOT NULL)");
        }
    }

    public function down(): void
    {
        if ($this->kolomAda()) {
            DB::statement('ALTER TABLE siapmst_shifts DROP COLUMN shift_fleksibel');
        }
    }

    private function kolomAda(): bool
    {
        return (int) DB::selectOne(
            "SELECT COUNT(*) AS c FROM user_tab_columns WHERE table_name = 'SIAPMST_SHIFTS' AND column_name = 'SHIFT_FLEKSIBEL'",
        )->c > 0;
    }
};
