<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Jadwal dinas bulanan — format "Jadwal Masuk Kerja Bulan …" di lapangan:
 * baris karyawan × tanggal, isi kode shift (P/S/…), I = izin, C = cuti; KOSONG = libur/off.
 *
 * - siapmst_shifts.shift_kode: kode 1–2 huruf yang tampil di grid (PAGI→P, SIANG→S, …).
 * - siaptxn_jadwals: satu baris per karyawan per tanggal yang DIJADWALKAN
 *   (jdw_jenis K = kerja + shift_id, I = izin, C = cuti). Tanggal tanpa baris = libur.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!$this->kolomAda('SIAPMST_SHIFTS', 'SHIFT_KODE')) {
            DB::statement('ALTER TABLE siapmst_shifts ADD (shift_kode VARCHAR2(2))');
            foreach (['PAGI' => 'P', 'SIANG' => 'S', 'MALAM' => 'M', 'NS' => 'N'] as $id => $kode) {
                DB::table('siapmst_shifts')->where('shift_id', $id)->update(['shift_kode' => $kode]);
            }
            // Shift lain (buatan user) → huruf pertama kode shift.
            DB::statement('UPDATE siapmst_shifts SET shift_kode = SUBSTR(shift_id, 1, 1) WHERE shift_kode IS NULL');
        }

        Schema::create('siaptxn_jadwals', function (Blueprint $table) {
            $table->bigIncrements('jdw_id');
            $table->string('emp_id', 20);
            $table->date('jdw_tanggal');
            $table->string('jdw_jenis', 1);           // K kerja / I izin / C cuti
            $table->string('shift_id', 10)->nullable(); // wajib bila K
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['emp_id', 'jdw_tanggal'], 'uk_siaptxn_jadwals_emp_tgl');
            $table->foreign('emp_id', 'fk_siaptxn_jadwals_emp')->references('emp_id')->on('siapmst_employers');
            $table->foreign('shift_id', 'fk_siaptxn_jadwals_shift')->references('shift_id')->on('siapmst_shifts');
        });

        Schema::table('siaptxn_jadwals', function (Blueprint $table) {
            $table->index('jdw_tanggal', 'idx_siaptxn_jadwals_tgl');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('siaptxn_jadwals');
        if ($this->kolomAda('SIAPMST_SHIFTS', 'SHIFT_KODE')) {
            DB::statement('ALTER TABLE siapmst_shifts DROP COLUMN shift_kode');
        }
    }

    private function kolomAda(string $tabel, string $kolom): bool
    {
        return (int) DB::selectOne(
            'SELECT COUNT(*) AS c FROM user_tab_columns WHERE table_name = ? AND column_name = ?',
            [$tabel, $kolom],
        )->c > 0;
    }
};
