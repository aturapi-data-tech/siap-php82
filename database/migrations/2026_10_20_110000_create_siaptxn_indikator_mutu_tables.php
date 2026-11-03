<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indikator mutu — kertas kerja digital (INM PMK 30/2022, IMP-Unit & IMP-RS SK 166/2026).
 * Definisi indikator (kolom isian, aturan patuh, target) ada di kode: App\Support\IndikatorMutu.
 *
 * - siaptxn_indikator_obs : satu baris = satu pengamatan (peluang / pasien / kejadian / hari).
 *     obs_isian      : isian kolom kertas kerja (JSON, dibaca di PHP — Oracle di sini tanpa JSON_VALUE).
 *     obs_pembilang / obs_penyebut : hasil hitung baris saat disimpan → rekap cukup SUM().
 *     unit_id NULL   : indikator tingkat RS (komplain, kepuasan, CP, MPP) — diisi Komite Mutu.
 *     obs_eksklusi   : '1' = memenuhi kriteria eksklusi (tetap tercatat, tidak dihitung).
 * - siaptxn_indikator_kuncis : bulan yang sudah divalidasi Komite Mutu — tidak bisa diubah lagi.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('siaptxn_indikator_obs', function (Blueprint $table) {
            $table->bigIncrements('obs_id');
            $table->string('ind_kode', 20);
            $table->string('unit_id', 10)->nullable();
            $table->date('obs_tanggal');
            $table->string('obs_isian', 4000)->nullable();
            $table->decimal('obs_pembilang', 12, 2)->default(0);
            $table->decimal('obs_penyebut', 12, 2)->default(0);
            $table->string('obs_eksklusi', 1)->default('0');
            $table->string('keterangan', 300)->nullable();
            $table->unsignedBigInteger('petugas_id')->nullable();
            $table->timestamps();

            $table->foreign('unit_id', 'fk_siaptxn_iobs_unit')
                ->references('unit_id')->on('siapmst_unit_kerjas');
            $table->index(['ind_kode', 'obs_tanggal'], 'idx_siaptxn_iobs_ind_tgl');
            $table->index('unit_id', 'idx_siaptxn_iobs_unit');
        });

        Schema::create('siaptxn_indikator_kuncis', function (Blueprint $table) {
            $table->string('periode', 6); // yyyymm
            $table->primary('periode', 'pk_siaptxn_indikator_kuncis');
            $table->unsignedBigInteger('dikunci_oleh')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('siaptxn_indikator_kuncis');
        Schema::dropIfExists('siaptxn_indikator_obs');
    }
};
