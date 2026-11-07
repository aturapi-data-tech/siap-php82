<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Laporan upah BPJS Ketenagakerjaan (template SIPP Online "template_upah_<NPP>.xlsx") dibuat dari SIAP tiap bulan
 * (App\Support\LaporanUpahBpjsTk). Data per karyawan yang dibutuhkan template dan belum ada di SIAP:
 * - siapmst_gaji_karyawans: bpjs_tk_kpj (nomor KPJ), bpjs_tk_kode (KODE_TK), bpjs_tk_id_pegawai (ID_PEGAWAI di SIPP, boleh kosong),
 *   bpjs_tk_upah (UPAH yang dilaporkan ke BPJS TK — nilai tetap per karyawan, bukan hitungan slip).
 * - siapmst_pengaturan_gajis: bpjs_tk_npp (nomor pendaftaran perusahaan di BPJS TK, kolom NPP).
 * NIK & tanggal lahir memakai kolom master karyawan yang sudah ada (emp_nik_ktp, emp_birth_date).
 * Pengisian awal lewat `php artisan gaji:impor-upah-bpjs-tk <template.xlsx>`.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement('ALTER TABLE siapmst_gaji_karyawans ADD (bpjs_tk_kpj VARCHAR2(20) NULL, bpjs_tk_kode VARCHAR2(20) NULL, bpjs_tk_id_pegawai VARCHAR2(20) NULL, bpjs_tk_upah NUMBER(14,2) NULL)');
        DB::statement('ALTER TABLE siapmst_pengaturan_gajis ADD (bpjs_tk_npp VARCHAR2(20) NULL)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE siapmst_pengaturan_gajis DROP (bpjs_tk_npp)');
        DB::statement('ALTER TABLE siapmst_gaji_karyawans DROP (bpjs_tk_kpj, bpjs_tk_kode, bpjs_tk_id_pegawai, bpjs_tk_upah)');
    }
};
