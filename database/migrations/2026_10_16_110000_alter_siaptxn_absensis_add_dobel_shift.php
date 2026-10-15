<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Dobel shift (tukar jadwal, mis. Pagi + Sore) — tetap SATU catatan per tanggal kerja.
 * dobel_shift_id = shift kedua yang ditandai HRD di Koreksi Rekap Harian; lembur catatan
 * itu dihitung dari jam kerja shift kedua (jeda antar-shift tidak ikut).
 * Idempotent: kolom yang sudah ada dilewati.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!$this->kolomAda()) {
            DB::statement('ALTER TABLE siaptxn_absensis ADD (dobel_shift_id VARCHAR2(10))');
            DB::statement('ALTER TABLE siaptxn_absensis ADD CONSTRAINT fk_siaptxn_absensis_dobel FOREIGN KEY (dobel_shift_id) REFERENCES siapmst_shifts (shift_id)');
        }
    }

    public function down(): void
    {
        if ($this->kolomAda()) {
            DB::statement('ALTER TABLE siaptxn_absensis DROP CONSTRAINT fk_siaptxn_absensis_dobel');
            DB::statement('ALTER TABLE siaptxn_absensis DROP COLUMN dobel_shift_id');
        }
    }

    private function kolomAda(): bool
    {
        return (int) DB::selectOne(
            'SELECT COUNT(*) AS c FROM user_tab_columns WHERE table_name = ? AND column_name = ?',
            ['SIAPTXN_ABSENSIS', 'DOBEL_SHIFT_ID'],
        )->c > 0;
    }
};
