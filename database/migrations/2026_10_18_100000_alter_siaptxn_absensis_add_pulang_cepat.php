<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * pulang_cepat_menit = menit pulang SEBELUM jadwal pulang shift (0 bila tidak / masih dalam
 * toleransi shift). Diisi saat absen pulang (kiosk / HP) & Koreksi Rekap Harian.
 * Idempotent: kolom yang sudah ada dilewati.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!$this->kolomAda()) {
            DB::statement('ALTER TABLE siaptxn_absensis ADD (pulang_cepat_menit NUMBER(5) DEFAULT 0)');
        }
    }

    public function down(): void
    {
        if ($this->kolomAda()) {
            DB::statement('ALTER TABLE siaptxn_absensis DROP COLUMN pulang_cepat_menit');
        }
    }

    private function kolomAda(): bool
    {
        return (int) DB::selectOne(
            'SELECT COUNT(*) AS c FROM user_tab_columns WHERE table_name = ? AND column_name = ?',
            ['SIAPTXN_ABSENSIS', 'PULANG_CEPAT_MENIT'],
        )->c > 0;
    }
};
