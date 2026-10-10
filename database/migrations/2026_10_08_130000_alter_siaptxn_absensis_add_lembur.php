<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Menit lembur = jam pulang aktual − jadwal pulang shift (dihitung App\Support\Absensi::lemburMenit;
 * di bawah ambang minimal dianggap 0). Idempotent: kolom yang sudah ada dilewati.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!$this->kolomAda()) {
            DB::statement('ALTER TABLE siaptxn_absensis ADD (lembur_menit NUMBER(5) DEFAULT 0 NOT NULL)');
        }
    }

    public function down(): void
    {
        if ($this->kolomAda()) {
            DB::statement('ALTER TABLE siaptxn_absensis DROP COLUMN lembur_menit');
        }
    }

    private function kolomAda(): bool
    {
        return (int) DB::selectOne(
            'SELECT COUNT(*) AS c FROM user_tab_columns WHERE table_name = ? AND column_name = ?',
            ['SIAPTXN_ABSENSIS', 'LEMBUR_MENIT'],
        )->c > 0;
    }
};
