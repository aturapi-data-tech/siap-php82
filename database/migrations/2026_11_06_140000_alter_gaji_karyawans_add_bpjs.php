<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BPJS dihitung OTOMATIS di slip (pola PPh 21) — pengganti sheet "BPJS" workbook gaji lama yang hasilnya dulu
 * ditulis ke kolom "Asuransi". Logika di App\Support\GajiKaryawan::hitungUlang() + App\Support\PengaturanGaji.
 *
 * - siapmst_gaji_karyawans (master per karyawan):
 *     bpjs_jkn       '1' = peserta BPJS Kesehatan → potongan 1 % × dasar × (1 + keluarga tambahan)
 *     bpjs_jkn_dasar dasar hitung JKN karyawan ini; NULL = UMK dari pengaturan (workbook: kolom R "Gaji Dasar hitung BPJS KES")
 *     bpjs_keluarga  jumlah keluarga tambahan yang iurannya dipotong dari karyawan (workbook: kolom S)
 *     bpjs_jp        '1' = peserta BPJS Ketenagakerjaan → potongan JP 1 % × upah bulan itu (pokok + tunjangan + JM, min. UMK)
 * - siapmst_pengaturan_gajis (satu baris id = 1): UMK, % JKN karyawan, % JP karyawan — ubah di Gaji Karyawan → Ketentuan BPJS.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement("ALTER TABLE siapmst_gaji_karyawans ADD (bpjs_jkn VARCHAR2(1) DEFAULT '0' NOT NULL, bpjs_jkn_dasar NUMBER(14,2) NULL, bpjs_keluarga NUMBER(3) DEFAULT 0 NOT NULL, bpjs_jp VARCHAR2(1) DEFAULT '0' NOT NULL)");

        Schema::create('siapmst_pengaturan_gajis', function (Blueprint $table) {
            $table->integer('id');
            $table->primary('id', 'pk_siapmst_pengaturan_gajis');
            $table->decimal('umk', 14, 2)->default(0);              // dasar JKN & batas bawah upah JP
            $table->decimal('persen_jkn', 5, 2)->default(1);         // % JKN bagian karyawan (per jiwa)
            $table->decimal('persen_jp', 5, 2)->default(1);          // % Jaminan Pensiun bagian karyawan
            $table->string('keterangan', 200)->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
        DB::table('siapmst_pengaturan_gajis')->insert(['id' => 1, 'umk' => 2628190, 'persen_jkn' => 1, 'persen_jp' => 1,
            'keterangan' => 'UMK Tulungagung (workbook gaji 2026)', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('siapmst_pengaturan_gajis');
        DB::statement('ALTER TABLE siapmst_gaji_karyawans DROP (bpjs_jkn, bpjs_jkn_dasar, bpjs_keluarga, bpjs_jp)');
    }
};
