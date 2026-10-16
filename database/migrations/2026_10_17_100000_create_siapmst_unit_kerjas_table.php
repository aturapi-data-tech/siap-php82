<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Unit kerja (Gizi, Rawat Inap, IGD, …) — pengelompokan karyawan & shift per unit.
 * - siapmst_employers.emp_unit_id : unit karyawan. Kepala ruang hanya mengatur jadwal unitnya
 *   (unit diambil dari karyawan yang terhubung ke akunnya, users.emp_id).
 * - siapmst_shifts.unit_id        : shift khusus unit; KOSONG = berlaku untuk semua unit.
 * - siapmst_shifts.shift_warna    : warna sel jadwal (#rrggbb); kosong = warna bawaan per kode.
 * Idempotent: kolom yang sudah ada dilewati.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('siapmst_unit_kerjas', function (Blueprint $table) {
            $table->string('unit_id', 10);
            $table->primary('unit_id', 'pk_siapmst_unit_kerjas');
            $table->string('unit_name', 100);
            $table->string('active_status', 1)->default('1');
            $table->timestamps();
        });

        if (!$this->kolomAda('SIAPMST_EMPLOYERS', 'EMP_UNIT_ID')) {
            DB::statement('ALTER TABLE siapmst_employers ADD (emp_unit_id VARCHAR2(10))');
            DB::statement('ALTER TABLE siapmst_employers ADD CONSTRAINT fk_siapmst_emp_unit FOREIGN KEY (emp_unit_id) REFERENCES siapmst_unit_kerjas (unit_id)');
        }
        if (!$this->kolomAda('SIAPMST_SHIFTS', 'UNIT_ID')) {
            DB::statement('ALTER TABLE siapmst_shifts ADD (unit_id VARCHAR2(10))');
            DB::statement('ALTER TABLE siapmst_shifts ADD CONSTRAINT fk_siapmst_shifts_unit FOREIGN KEY (unit_id) REFERENCES siapmst_unit_kerjas (unit_id)');
        }
        if (!$this->kolomAda('SIAPMST_SHIFTS', 'SHIFT_WARNA')) {
            DB::statement('ALTER TABLE siapmst_shifts ADD (shift_warna VARCHAR2(7))');
        }
    }

    public function down(): void
    {
        if ($this->kolomAda('SIAPMST_SHIFTS', 'SHIFT_WARNA')) {
            DB::statement('ALTER TABLE siapmst_shifts DROP COLUMN shift_warna');
        }
        if ($this->kolomAda('SIAPMST_SHIFTS', 'UNIT_ID')) {
            DB::statement('ALTER TABLE siapmst_shifts DROP CONSTRAINT fk_siapmst_shifts_unit');
            DB::statement('ALTER TABLE siapmst_shifts DROP COLUMN unit_id');
        }
        if ($this->kolomAda('SIAPMST_EMPLOYERS', 'EMP_UNIT_ID')) {
            DB::statement('ALTER TABLE siapmst_employers DROP CONSTRAINT fk_siapmst_emp_unit');
            DB::statement('ALTER TABLE siapmst_employers DROP COLUMN emp_unit_id');
        }
        Schema::dropIfExists('siapmst_unit_kerjas');
    }

    private function kolomAda(string $tabel, string $kolom): bool
    {
        return (int) DB::selectOne(
            'SELECT COUNT(*) AS c FROM user_tab_columns WHERE table_name = ? AND column_name = ?',
            [$tabel, $kolom],
        )->c > 0;
    }
};
