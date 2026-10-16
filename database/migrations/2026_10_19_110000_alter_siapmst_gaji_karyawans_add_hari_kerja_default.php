<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * hari_kerja_default = jumlah hari kerja per bulan yang dipakai slip BARU sebagai pengali tarif
 * makan & transport. Di slip, hari kerja bisa diedit (cuti / izin / tambahan); jumlah hadir
 * menurut absensi hanya ditampilkan sebagai acuan.
 * Idempotent: kolom yang sudah ada dilewati.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!$this->kolomAda()) {
            DB::statement('ALTER TABLE siapmst_gaji_karyawans ADD (hari_kerja_default NUMBER(3) DEFAULT 25 NOT NULL)');
        }
    }

    public function down(): void
    {
        if ($this->kolomAda()) {
            DB::statement('ALTER TABLE siapmst_gaji_karyawans DROP COLUMN hari_kerja_default');
        }
    }

    private function kolomAda(): bool
    {
        return (int) DB::selectOne(
            'SELECT COUNT(*) AS c FROM user_tab_columns WHERE table_name = ? AND column_name = ?',
            ['SIAPMST_GAJI_KARYAWANS', 'HARI_KERJA_DEFAULT'],
        )->c > 0;
    }
};
