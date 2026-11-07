<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bersih-bersih model pemindahan gaji dokter: struktur gaji kini selalu disetel di SIAP (tidak ada lagi sinkron ke SIRUS
 * lewat API), jadi kolom status kirim di siapmst_gdok_strukturs dan pilihan sumber struktur di pengaturan dibuang.
 * Kolom sumber pada struktur tetap: SIRUS = dimuat dari berkas ekspor, SIAP = sudah diubah di SIAP (tidak ditimpa saat muat ulang).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('siapmst_gdok_strukturs', fn(Blueprint $table) => $table->dropColumn(['disinkron_at', 'sinkron_pesan']));
        Schema::table('siapmst_pengaturan_gdoks', fn(Blueprint $table) => $table->dropColumn('sumber_struktur'));
    }

    public function down(): void
    {
        Schema::table('siapmst_pengaturan_gdoks', fn(Blueprint $table) => $table->string('sumber_struktur', 5)->default('SIRUS'));
        Schema::table('siapmst_gdok_strukturs', function (Blueprint $table) {
            $table->timestamp('disinkron_at')->nullable();
            $table->string('sinkron_pesan', 200)->nullable();
        });
    }
};
