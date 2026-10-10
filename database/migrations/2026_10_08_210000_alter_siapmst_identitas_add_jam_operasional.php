<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Jam operasional instansi (company profile), mis. "Pagi 07.00–12.00 · Sore 17.00–21.00". */
return new class extends Migration {
    public function up(): void
    {
        if (!$this->kolomAda()) {
            DB::statement('ALTER TABLE siapmst_identitas ADD (jam_operasional VARCHAR2(150))');
        }
    }

    public function down(): void
    {
        if ($this->kolomAda()) {
            DB::statement('ALTER TABLE siapmst_identitas DROP COLUMN jam_operasional');
        }
    }

    private function kolomAda(): bool
    {
        return (int) DB::selectOne(
            'SELECT COUNT(*) AS c FROM user_tab_columns WHERE table_name = ? AND column_name = ?',
            ['SIAPMST_IDENTITAS', 'JAM_OPERASIONAL'],
        )->c > 0;
    }
};
