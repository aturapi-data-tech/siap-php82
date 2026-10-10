<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Perbaikan / kerusakan alat — format "Laporan Perbaikan Alat Medis" RS (bukti MFK 7 EP d)
 * + alur Pedoman Pengelolaan Alat Medis: lapor → teknisi respon (±15 mnt) & analisa →
 * (bila perlu) vendor + service report → selesai → diserahkan kembali; biaya dicatat.
 *
 * pb_status: B dilaporkan · P ditangani teknisi · V di vendor · S selesai dikerjakan ·
 *            K diserahkan kembali · X tidak dapat diperbaiki.
 * pb_pelaksana: I teknisi internal RS · V vendor.
 * pm_id: asal temuan checklist pemeliharaan (opsional).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('siaptxn_perbaikans', function (Blueprint $table) {
            $table->bigIncrements('pb_id');
            $table->string('pb_no', 30);
            $table->string('asset_id', 30);
            $table->string('ruang_id', 10)->nullable();      // ruang saat dilaporkan
            $table->string('pb_status', 1)->default('B');
            $table->timestamp('tgl_permintaan');
            $table->string('pelapor', 100)->nullable();
            $table->string('kerusakan', 500);
            $table->timestamp('tgl_respon')->nullable();
            $table->string('teknisi', 100)->nullable();
            $table->string('analisa', 500)->nullable();
            $table->string('pb_pelaksana', 1)->nullable();   // I / V
            $table->string('vendor', 100)->nullable();
            $table->string('no_service_report', 60)->nullable();
            $table->string('suku_cadang', 500)->nullable();
            $table->decimal('biaya', 14, 2)->default(0);
            $table->timestamp('tgl_selesai')->nullable();
            $table->timestamp('tgl_diserahkan')->nullable();
            $table->string('penerima', 100)->nullable();
            $table->string('keterangan', 500)->nullable();
            $table->unsignedBigInteger('pm_id')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique('pb_no', 'uk_siaptxn_perbaikans_no');
            $table->foreign('asset_id', 'fk_siaptxn_pb_asset')
                ->references('asset_id')->on('siapmst_assets');
        });

        Schema::table('siaptxn_perbaikans', function (Blueprint $table) {
            $table->index('asset_id', 'idx_siaptxn_pb_asset');
            $table->index('pb_status', 'idx_siaptxn_pb_status');
            $table->index('tgl_permintaan', 'idx_siaptxn_pb_tgl');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('siaptxn_perbaikans');
    }
};
