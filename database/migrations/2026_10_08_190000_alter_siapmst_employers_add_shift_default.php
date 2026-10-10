<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Kolom "P/S" di formulir jadwal lapangan → shift default per karyawan.
 * Ditampilkan di samping nama pada Jadwal Dinas & dipakai tombol "Isi Sen–Sab".
 */
return new class extends Migration {
    public function up(): void
    {
        if (!$this->kolomAda()) {
            DB::statement('ALTER TABLE siapmst_employers ADD (emp_shift_default VARCHAR2(10))');
            DB::statement('ALTER TABLE siapmst_employers ADD CONSTRAINT fk_siapmst_emp_shift_def FOREIGN KEY (emp_shift_default) REFERENCES siapmst_shifts (shift_id)');
        }
    }

    public function down(): void
    {
        if ($this->kolomAda()) {
            DB::statement('ALTER TABLE siapmst_employers DROP CONSTRAINT fk_siapmst_emp_shift_def');
            DB::statement('ALTER TABLE siapmst_employers DROP COLUMN emp_shift_default');
        }
    }

    private function kolomAda(): bool
    {
        return (int) DB::selectOne(
            'SELECT COUNT(*) AS c FROM user_tab_columns WHERE table_name = ? AND column_name = ?',
            ['SIAPMST_EMPLOYERS', 'EMP_SHIFT_DEFAULT'],
        )->c > 0;
    }
};
