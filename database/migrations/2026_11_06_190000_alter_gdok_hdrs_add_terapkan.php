<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jejak "Terapkan ke slip gaji" pada snapshot gaji dokter (SIRUS): versi mana yang ditulis ke slip Gaji Karyawan,
 * kapan, oleh siapa, dan ke gaji_id mana. Dicatat per versi (hdr), jadi versi baru yang belum diterapkan terlihat di Buku.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('siaptxn_gdok_hdrs', function (Blueprint $table) {
            $table->unsignedBigInteger('gaji_id')->nullable();
            $table->timestamp('diterapkan_at')->nullable();
            $table->unsignedBigInteger('diterapkan_oleh')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('siaptxn_gdok_hdrs', function (Blueprint $table) {
            $table->dropColumn(['gaji_id', 'diterapkan_at', 'diterapkan_oleh']);
        });
    }
};
