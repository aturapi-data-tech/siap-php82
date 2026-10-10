<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Master ruang / unit — lokasi alat (inventaris) dan dasar kode alat
 * (mis. APT.TG.01/2015 → ruang APT). ruang_id singkat, kapital.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('siapmst_ruangs', function (Blueprint $table) {
            $table->string('ruang_id', 10);
            $table->primary('ruang_id', 'pk_siapmst_ruangs');
            $table->string('ruang_name', 100);
            $table->string('active_status', 1)->default('1');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('siapmst_ruangs');
    }
};
