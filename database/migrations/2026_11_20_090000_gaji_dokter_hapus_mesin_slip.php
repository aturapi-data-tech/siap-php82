<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gaji dokter tinggal satu alur: SIRUS hanya memasok transaksi (endpoint slip SIRUS sudah dihapus), slip selalu dihitung SIAP
 * lewat "Ambil dari SIRUS". Sakelar mesin slip SIRUS|SIAP (siapmst_pengaturan_gdoks, App\Support\PengaturanGajiDokter) dibuang.
 * Snapshot lama ber-sumber SIRUS tetap ada sebagai arsip baca.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::dropIfExists('siapmst_pengaturan_gdoks');
    }

    public function down(): void
    {
        Schema::create('siapmst_pengaturan_gdoks', function (Blueprint $table) {
            $table->increments('id');
            $table->string('mesin_slip', 5)->default('SIRUS');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
        DB::table('siapmst_pengaturan_gdoks')->insert(['id' => 1, 'mesin_slip' => 'SIAP', 'created_at' => now(), 'updated_at' => now()]);
    }
};
