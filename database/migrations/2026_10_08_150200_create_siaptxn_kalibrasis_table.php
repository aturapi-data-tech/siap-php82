<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Riwayat kalibrasi alat — satu baris per pelaksanaan (mengikuti isi Sertifikat Kalibrasi:
 * tanggal, lembaga/pelaksana, nomor sertifikat, hasil, disarankan kalibrasi ulang).
 * kal_hasil: L = Laik Pakai, T = Tidak Laik Pakai.
 * kal_berlaku_sd = tanggal kalibrasi ulang (default tanggal kalibrasi + interval alat).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('siaptxn_kalibrasis', function (Blueprint $table) {
            $table->bigIncrements('kal_id');
            $table->string('asset_id', 30);
            $table->date('kal_tanggal');
            $table->string('kal_pelaksana', 100)->nullable();
            $table->string('kal_no_sertifikat', 60)->nullable();
            $table->string('kal_hasil', 1)->default('L');
            $table->date('kal_berlaku_sd')->nullable();
            $table->string('keterangan', 200)->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('asset_id', 'fk_siaptxn_kalibrasis_asset')
                ->references('asset_id')->on('siapmst_assets');
        });

        Schema::table('siaptxn_kalibrasis', function (Blueprint $table) {
            $table->index('asset_id', 'idx_siaptxn_kalibrasis_asset');
            $table->index('kal_berlaku_sd', 'idx_siaptxn_kalibrasis_sd');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('siaptxn_kalibrasis');
    }
};
