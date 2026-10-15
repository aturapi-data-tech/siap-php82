<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Aturan absensi yang bisa diubah HRD — SATU BARIS (id = 1), diatur di Absensi › Pengaturan Absensi.
 * Dibaca lewat App\Support\PengaturanAbsensi (di-cache). Nilai awal = aturan yang sebelumnya
 * tertulis sebagai konstanta di App\Support\Absensi & LokasiAbsen.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('siapmst_pengaturan_absensis', function (Blueprint $table) {
            $table->integer('id');
            $table->primary('id', 'pk_siapmst_pengaturan_absensis');
            $table->integer('jeda_min_pulang_menit')->default(10);
            $table->integer('min_lembur_menit')->default(30);
            $table->integer('batas_pulang_jam')->default(6);
            $table->integer('batas_jam_lintas_hari')->default(12);
            $table->string('absen_hp_aktif', 1)->default('1');
            $table->integer('maks_akurasi_meter')->default(500);
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });

        DB::table('siapmst_pengaturan_absensis')->insert([
            'id'                    => 1,
            'jeda_min_pulang_menit' => 10,
            'min_lembur_menit'      => 30,
            'batas_pulang_jam'      => 6,
            'batas_jam_lintas_hari' => 12,
            'absen_hp_aktif'        => '1',
            'maks_akurasi_meter'    => 500,
            'created_at'            => now(),
            'updated_at'            => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('siapmst_pengaturan_absensis');
    }
};
