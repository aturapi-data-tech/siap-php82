<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * VERSI DIPAKAI gaji dokter (pola SIKEU "versi disahkan"): tiap dokter per bulan jasa punya tepat satu versi dipakai = yang
 * tampil di Buku, dikunci Final, dan ditulis ke slip gaji. Ambil ulang yang berbeda menyimpan versi baru (terbaru = '1')
 * TANPA menggeser versi dipakai; Keuangan memilihnya sendiri setelah membandingkan (Bandingkan Versi → Pakai versi ini).
 * Data lama: versi terbaru saat migrasi = versi dipakai (perilaku sebelumnya).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('siaptxn_gdok_hdrs', function (Blueprint $table) {
            $table->string('dipakai', 1)->default('0');
            $table->timestamp('dipakai_at')->nullable();
            $table->unsignedBigInteger('dipakai_oleh')->nullable();
        });
        DB::table('siaptxn_gdok_hdrs')->where('terbaru', '1')->update(['dipakai' => '1']);
        Schema::table('siaptxn_gdok_hdrs', fn(Blueprint $table) => $table->index(['periode', 'dipakai'], 'idx_siaptxn_gdok_hdrs_dipakai'));
    }

    public function down(): void
    {
        Schema::table('siaptxn_gdok_hdrs', function (Blueprint $table) {
            $table->dropIndex('idx_siaptxn_gdok_hdrs_dipakai');
            $table->dropColumn(['dipakai', 'dipakai_at', 'dipakai_oleh']);
        });
    }
};
