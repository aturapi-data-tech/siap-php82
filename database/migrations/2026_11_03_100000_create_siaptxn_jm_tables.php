<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pembagian Jasa Medik (JM) karyawan per bulan — pengganti sheet "JaMed" workbook gaji lama.
 * Definisi pos (Rawat Inap, UGD, Poli, Resep, Laborat) ada di kode: App\Support\JasaMedik.
 *
 * - siaptxn_jm_pos   : per bulan per pos — nilai dasar (perolehan JM / pendapatan), % JM dari dasar,
 *                      % bagian pegawai (sisanya bagian RS / BPI).
 * - siapmst_jm_poins : MASTER poin karyawan per pos (diisi di Master Karyawan → Gaji) — sumber Generate.
 * - siaptxn_jm_poins : poin karyawan per bulan per pos (hasil Generate dari master, bisa disesuaikan).
 *                      JM = poin × (bagian pegawai ÷ total poin pos).
 *                      manual '1' = diubah di layar bulan itu → dipertahankan saat Generate ulang.
 * - siaptxn_jm_hdrs  : jejak "Terapkan ke slip" per bulan.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('siaptxn_jm_pos', function (Blueprint $table) {
            $table->string('periode', 7);
            $table->string('pos_kode', 10);
            $table->decimal('nilai_dasar', 16, 2)->default(0);
            $table->decimal('persen_jm', 7, 3)->default(100);
            $table->decimal('persen_pegawai', 7, 3)->default(100);
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->primary(['periode', 'pos_kode'], 'pk_siaptxn_jm_pos');
        });

        Schema::create('siapmst_jm_poins', function (Blueprint $table) {
            $table->string('emp_id', 20);
            $table->string('pos_kode', 10);
            $table->decimal('poin', 8, 2)->default(0);
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->primary(['emp_id', 'pos_kode'], 'pk_siapmst_jm_poins');
            $table->foreign('emp_id', 'fk_siapmst_jm_poins_emp')->references('emp_id')->on('siapmst_employers');
        });

        Schema::create('siaptxn_jm_poins', function (Blueprint $table) {
            $table->string('periode', 7);
            $table->string('emp_id', 20);
            $table->string('pos_kode', 10);
            $table->decimal('poin', 8, 2)->default(0);
            $table->string('manual', 1)->default('0');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->primary(['periode', 'emp_id', 'pos_kode'], 'pk_siaptxn_jm_poins');
            $table->foreign('emp_id', 'fk_siaptxn_jm_poins_emp')->references('emp_id')->on('siapmst_employers');
        });

        Schema::create('siaptxn_jm_hdrs', function (Blueprint $table) {
            $table->string('periode', 7)->primary('pk_siaptxn_jm_hdrs');
            $table->timestamp('diterapkan_at')->nullable();
            $table->unsignedBigInteger('diterapkan_oleh')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('siaptxn_jm_hdrs');
        Schema::dropIfExists('siaptxn_jm_poins');
        Schema::dropIfExists('siapmst_jm_poins');
        Schema::dropIfExists('siaptxn_jm_pos');
    }
};
