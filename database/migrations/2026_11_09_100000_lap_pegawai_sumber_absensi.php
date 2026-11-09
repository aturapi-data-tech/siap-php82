<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Laporan Kepegawaian: sakelar per bulan "Ambil dari Rekap Absensi" (App\Support\LaporanPegawai).
 * - sumber_absensi : '1' = hari kerja, cuti & jam lembur bulan itu dibaca dari modul absensi; NULL = isian kepegawaian.
 * - sumber_absensi_at / _oleh : jejak siapa & kapan sakelar terakhir diubah.
 * Isian kepegawaian di siaptxn_lap_pegawais tidak disentuh sakelar ini, jadi kembali utuh saat dimatikan.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement('ALTER TABLE siaptxn_lap_pegawai_hdrs ADD (sumber_absensi VARCHAR2(1) NULL, sumber_absensi_at TIMESTAMP NULL, sumber_absensi_oleh NUMBER(19) NULL)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE siaptxn_lap_pegawai_hdrs DROP (sumber_absensi, sumber_absensi_at, sumber_absensi_oleh)');
    }
};
