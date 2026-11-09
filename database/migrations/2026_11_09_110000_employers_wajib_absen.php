<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Status Wajib Absen per karyawan (Master Karyawan): '1' = ikut Jadwal Dinas, Rekap Harian, Generate Libur/Alpa, Rekap Bulanan,
 * dan angkanya bisa diambil sakelar Laporan Kepegawaian; '0' = tidak (mis. manajemen — tetap digaji, angka laporan selalu isian manual).
 * Nilai awal: semua wajib, kecuali unit Dokter Spesialis, Dokter Umum, Pengurus PT.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement("ALTER TABLE siapmst_employers ADD (emp_wajib_absen VARCHAR2(1) DEFAULT '1' NOT NULL)");
        DB::table('siapmst_employers')->whereIn('emp_unit_id', ['DS', 'DK', 'PT'])->update(['emp_wajib_absen' => '0']);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE siapmst_employers DROP (emp_wajib_absen)');
    }
};
