<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventaris alat — kolom mengikuti formulir "Daftar Inventaris" RSUI Madinah (bukti MFK 7):
 * kode alat, nama, jumlah, merk, type/model, tahun pengadaan, ruang, kondisi (B/RR/RB).
 * asset_jenis: M = alat medis, N = alat non medis.
 * Kalibrasi (Permenkes 54/2015: paling sedikit 1x setahun) → wajib_kalibrasi + interval bulan;
 * riwayatnya di siaptxn_kalibrasis.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('siapmst_assets', function (Blueprint $table) {
            $table->string('asset_id', 30);           // kode alat, mis. IGD.SP.01/2025
            $table->primary('asset_id', 'pk_siapmst_assets');
            $table->string('asset_name', 100);
            $table->string('asset_jenis', 1)->default('M'); // M medis / N non medis
            $table->string('merk', 60)->nullable();
            $table->string('tipe_model', 60)->nullable();
            $table->string('no_seri', 60)->nullable();
            $table->string('ruang_id', 10);
            $table->integer('tahun_pengadaan')->nullable();
            $table->integer('jumlah')->default(1);
            $table->string('kondisi', 2)->default('B');  // B baik / RR rusak ringan / RB rusak berat
            $table->string('wajib_kalibrasi', 1)->default('0');
            $table->integer('interval_kalibrasi')->default(12); // bulan
            $table->string('keterangan', 200)->nullable();
            $table->string('active_status', 1)->default('1');
            $table->timestamps();

            $table->foreign('ruang_id', 'fk_siapmst_assets_ruang')
                ->references('ruang_id')->on('siapmst_ruangs');
        });

        Schema::table('siapmst_assets', function (Blueprint $table) {
            $table->index('ruang_id', 'idx_siapmst_assets_ruang');
            $table->index('asset_name', 'idx_siapmst_assets_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('siapmst_assets');
    }
};
