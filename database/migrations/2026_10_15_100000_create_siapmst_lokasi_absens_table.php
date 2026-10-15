<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Titik lokasi kerja (RS / klinik) untuk absen lewat HP.
 * Karyawan boleh absen bila posisi GPS-nya ≤ radius_meter dari titik ini.
 * Koordinat desimal WGS84 (sama dengan Google Maps).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('siapmst_lokasi_absens', function (Blueprint $table) {
            $table->string('lokasi_id', 10);
            $table->primary('lokasi_id', 'pk_siapmst_lokasi_absens');
            $table->string('lokasi_name', 100);
            $table->string('alamat', 200)->nullable();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->integer('radius_meter')->default(2000);
            $table->string('active_status', 1)->default('1');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('siapmst_lokasi_absens');
    }
};
