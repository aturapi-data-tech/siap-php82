<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gaji karyawan — pola mengikuti gaji dokter sirus-php82 (header + detail, Draft → Final).
 *
 * siapmst_gaji_karyawans : parameter gaji per karyawan (gaji pokok, tarif harian, tunjangan &
 *                          potongan rutin, PPh 21 %). Diubah kapan saja oleh bagian keuangan.
 * siaptxn_gaji_hdrs      : satu slip per karyawan per periode (YYYY-MM). Parameter DI-SNAPSHOT
 *                          saat generate — revisi gaji pokok tidak mengubah slip lama.
 * siaptxn_gaji_dtls      : baris rincian slip. jenis D = pendapatan (positif),
 *                          P = potongan (NEGATIF) → gaji diterima = SUM(nilai).
 *                          otomatis '1' = dibuat generate dari parameter; '0' = diisi petugas.
 *                          nilai_manual '1' = nilai baris otomatis ditimpa petugas (dipertahankan saat generate ulang).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('siapmst_gaji_karyawans', function (Blueprint $table) {
            $table->string('emp_id', 20)->primary();
            $table->decimal('gaji_pokok', 14, 2)->default(0);
            $table->decimal('tarif_makan', 14, 2)->default(0);       // per hari hadir
            $table->decimal('tarif_transport', 14, 2)->default(0);   // per hari hadir
            $table->decimal('tunjangan_struktural', 14, 2)->default(0);
            $table->decimal('tunjangan_profesi', 14, 2)->default(0);
            $table->decimal('potongan_asuransi', 14, 2)->default(0);
            $table->decimal('potongan_organisasi', 14, 2)->default(0);
            $table->decimal('potongan_arisan', 14, 2)->default(0);
            $table->decimal('potongan_koperasi', 14, 2)->default(0);
            $table->decimal('potongan_infaq', 14, 2)->default(0);
            $table->decimal('potongan_bpjs', 14, 2)->default(0);
            $table->decimal('pph21_persen', 5, 2)->default(0);
            $table->string('nama_bank', 30)->nullable();
            $table->string('no_rekening', 30)->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('emp_id', 'fk_siapmst_gaji_kry_emp')->references('emp_id')->on('siapmst_employers');
        });

        Schema::create('siaptxn_gaji_hdrs', function (Blueprint $table) {
            $table->bigIncrements('gaji_id');
            $table->string('emp_id', 20);
            $table->string('periode', 7);                 // YYYY-MM (bulan kerja)
            $table->string('unit_id', 10)->nullable();    // snapshot unit saat generate
            $table->integer('hari_hadir')->default(0);
            $table->decimal('gaji_pokok', 14, 2)->default(0);
            $table->decimal('tarif_makan', 14, 2)->default(0);
            $table->decimal('tarif_transport', 14, 2)->default(0);
            $table->decimal('pph21_persen', 5, 2)->default(0);
            $table->decimal('total_pendapatan', 14, 2)->default(0);
            $table->decimal('total_potongan', 14, 2)->default(0);   // NEGATIF
            $table->decimal('gaji_diterima', 14, 2)->default(0);
            $table->string('gaji_status', 1)->default('D');         // D = draft, F = final
            $table->unsignedBigInteger('difinalkan_oleh')->nullable();
            $table->timestamp('difinalkan_at')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['emp_id', 'periode'], 'uk_siaptxn_gaji_hdrs_emp_per');
            $table->index('periode', 'idx_siaptxn_gaji_hdrs_per');
            $table->foreign('emp_id', 'fk_siaptxn_gaji_hdrs_emp')->references('emp_id')->on('siapmst_employers');
        });

        Schema::create('siaptxn_gaji_dtls', function (Blueprint $table) {
            $table->bigIncrements('dtl_id');
            $table->unsignedBigInteger('gaji_id');
            $table->string('jenis', 1);                  // D = pendapatan, P = potongan
            $table->string('kode', 20);
            $table->string('keterangan', 100)->nullable();
            $table->decimal('jumlah', 8, 2)->default(0); // mis. hari hadir
            $table->decimal('tarif', 14, 2)->default(0);
            $table->decimal('nilai', 14, 2)->default(0);
            $table->string('otomatis', 1)->default('0');
            $table->string('nilai_manual', 1)->default('0');
            $table->integer('urutan')->default(0);
            $table->timestamps();

            $table->index('gaji_id', 'idx_siaptxn_gaji_dtls_hdr');
            $table->foreign('gaji_id', 'fk_siaptxn_gaji_dtls_hdr')->references('gaji_id')->on('siaptxn_gaji_hdrs')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('siaptxn_gaji_dtls');
        Schema::dropIfExists('siaptxn_gaji_hdrs');
        Schema::dropIfExists('siapmst_gaji_karyawans');
    }
};
