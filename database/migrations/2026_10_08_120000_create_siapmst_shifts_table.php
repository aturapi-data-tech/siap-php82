<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Master shift kerja. Jam disimpan 'HH:MM' (VARCHAR2) — cukup untuk jadwal harian
 * dan bebas masalah zona/tanggal Oracle.
 * lintas_hari '1' = jam pulang jatuh di hari berikutnya (mis. shift malam 21:00–07:00).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('siapmst_shifts', function (Blueprint $table) {
            $table->string('shift_id', 10);
            $table->primary('shift_id', 'pk_siapmst_shifts');
            $table->string('shift_name', 50);
            $table->string('jam_masuk', 5);  // HH:MM
            $table->string('jam_pulang', 5); // HH:MM
            $table->integer('toleransi_menit')->default(0);
            $table->string('lintas_hari', 1)->default('0');
            $table->string('active_status', 1)->default('1');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('siapmst_shifts');
    }
};
