<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laporan Kepegawaian bulanan — pengganti sheet "Lap Pegawai" workbook gaji lama
 * (hari kerja, cuti, jam lembur per karyawan; diisi Kasubbag Kepegawaian, dipakai bagian gaji).
 * Berdiri sendiri — TIDAK terhubung ke modul absensi. Logika di App\Support\LaporanPegawai.
 *
 * - siaptxn_lap_pegawais     : per bulan per karyawan — hari kerja, cuti, jam lembur, keterangan.
 * - siaptxn_lap_pegawai_hdrs : jejak "Terapkan ke Slip" per bulan.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('siaptxn_lap_pegawais', function (Blueprint $table) {
            $table->string('periode', 7);
            $table->string('emp_id', 20);
            $table->integer('hari_kerja')->default(0);
            $table->integer('cuti')->default(0);
            $table->decimal('lembur_jam', 6, 2)->default(0);
            $table->string('keterangan', 200)->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->primary(['periode', 'emp_id'], 'pk_siaptxn_lap_pegawais');
            $table->foreign('emp_id', 'fk_siaptxn_lap_pegawais_emp')->references('emp_id')->on('siapmst_employers');
        });

        Schema::create('siaptxn_lap_pegawai_hdrs', function (Blueprint $table) {
            $table->string('periode', 7)->primary('pk_siaptxn_lap_pegawai_hdrs');
            $table->timestamp('diterapkan_at')->nullable();
            $table->unsignedBigInteger('diterapkan_oleh')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('siaptxn_lap_pegawai_hdrs');
        Schema::dropIfExists('siaptxn_lap_pegawais');
    }
};
