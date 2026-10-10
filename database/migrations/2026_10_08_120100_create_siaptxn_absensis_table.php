<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Absensi harian — satu baris per karyawan per tanggal kerja.
 *
 * abs_date = tanggal MULAI shift (shift malam yang pulang esok pagi tetap tercatat
 * di tanggal masuknya, supaya rekap tidak pecah dua hari).
 * abs_status: H=Hadir, T=Terlambat, I=Izin, S=Sakit, C=Cuti, A=Alpa.
 * sumber: KIOSK (absen mandiri) / MANUAL (diisi admin di rekap harian).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('siaptxn_absensis', function (Blueprint $table) {
            $table->bigIncrements('abs_id');
            $table->string('emp_id', 20);
            $table->date('abs_date');
            $table->string('shift_id', 10)->nullable();
            $table->timestamp('jam_masuk')->nullable();
            $table->timestamp('jam_pulang')->nullable();
            $table->string('abs_status', 1);
            $table->integer('terlambat_menit')->default(0);
            $table->string('keterangan', 200)->nullable();
            $table->string('sumber', 10)->default('KIOSK');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['emp_id', 'abs_date'], 'uk_siaptxn_absensis_emp_tgl');
            $table->foreign('emp_id', 'fk_siaptxn_absensis_emp')
                ->references('emp_id')->on('siapmst_employers');
            $table->foreign('shift_id', 'fk_siaptxn_absensis_shift')
                ->references('shift_id')->on('siapmst_shifts');
        });

        Schema::table('siaptxn_absensis', function (Blueprint $table) {
            $table->index('abs_date', 'idx_siaptxn_absensis_tgl');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('siaptxn_absensis');
    }
};
