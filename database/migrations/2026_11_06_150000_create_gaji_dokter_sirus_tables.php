<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gaji Dokter dari SIRUS — snapshot BERVERSI per bulan jasa (App\Support\GajiDokterSirus).
 * SIAP menarik slip gaji dokter SIRUS lewat API (JWT), menyimpannya apa adanya, dan tidak pernah mengubah data SIRUS.
 * Tiap "Ambil dari SIRUS" membandingkan checksum: data sama → hanya tanggal ambil diperbarui; berubah → versi baru
 * (versi lama tetap, jadi "apa yang diproses bulan lalu" selalu bisa dibandingkan dengan keadaan SIRUS sekarang).
 *
 * - siapmst_dokter_sirus : master dokter SIRUS + pemetaan ke karyawan SIAP (emp_id); OTO = cocok nama, MANUAL = dipilih.
 * - siaptxn_gdok_hdrs    : header slip per (periode, dr_id, versi); terbaru '1' = versi yang berlaku.
 * - siaptxn_gdok_dtls    : komponen slip (J jasa / P potongan / T tambahan).
 * - siaptxn_gdok_lamps   : lampiran rincian pasien per transaksi.
 * - siaptxn_gdok_ambils  : jejak tiap penarikan.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('siapmst_dokter_sirus', function (Blueprint $table) {
            $table->string('dr_id', 10)->primary();
            $table->string('dr_name', 100);
            $table->string('dr_nik', 30)->nullable();
            $table->string('kd_dr_bpjs', 20)->nullable();
            $table->string('aktif', 1)->default('1');
            $table->string('emp_id', 20)->nullable();
            $table->string('cara_map', 6)->nullable();   // OTO | MANUAL
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->foreign('emp_id', 'fk_siapmst_dokter_sirus_emp')->references('emp_id')->on('siapmst_employers');
        });

        Schema::create('siaptxn_gdok_hdrs', function (Blueprint $table) {
            $table->bigIncrements('gd_id');
            $table->string('periode', 7);                      // bulan JASA (YYYY-MM)
            $table->string('dr_id', 10);
            $table->integer('versi')->default(1);
            $table->string('terbaru', 1)->default('1');
            $table->string('emp_id', 20)->nullable();           // pemetaan saat diambil
            $table->unsignedBigInteger('gajidoctor_no');
            $table->string('dr_name', 100);
            $table->string('periode_gaji', 7);                 // bulan GAJI di SIRUS (bulan jasa + 1)
            $table->string('skema_gaji_pokok', 1)->default('A');
            $table->decimal('nilai_gaji_pokok', 14, 2)->default(0);
            $table->string('potongan_rs_basis', 1)->default('T');
            $table->decimal('potongan_rs_persen', 5, 2)->default(0);
            $table->decimal('pph21_persen', 5, 2)->default(0);
            $table->string('npwp_status', 1)->default('Y');
            $table->decimal('jasa_total', 14, 2)->default(0);
            $table->decimal('total_gaji', 14, 2)->default(0);
            $table->decimal('potongan_rs', 14, 2)->default(0);
            $table->decimal('pph21', 14, 2)->default(0);
            $table->decimal('potongan_lain_total', 14, 2)->default(0);
            $table->decimal('tambahan_total', 14, 2)->default(0);
            $table->decimal('gaji_diterima', 14, 2)->default(0);
            $table->string('gaji_status', 1)->default('D');
            $table->date('tanggal_bayar')->nullable();
            $table->string('update_date_sirus', 19)->nullable();
            $table->string('checksum', 32);
            $table->integer('jumlah_lampiran')->default(0);
            $table->timestamp('diambil_at')->nullable();
            $table->unsignedBigInteger('diambil_oleh')->nullable();
            $table->timestamps();
            $table->unique(['periode', 'dr_id', 'versi'], 'uk_siaptxn_gdok_hdrs');
            $table->index(['periode', 'terbaru'], 'idx_siaptxn_gdok_hdrs_per');
        });

        Schema::create('siaptxn_gdok_dtls', function (Blueprint $table) {
            $table->bigIncrements('gd_dtl');
            $table->unsignedBigInteger('gd_id');
            $table->string('jenis', 1);
            $table->string('kode', 20);
            $table->string('label', 100)->nullable();
            $table->string('keterangan', 100)->nullable();
            $table->decimal('nilai', 14, 2)->default(0);
            $table->string('nilai_manual', 1)->default('0');
            $table->integer('jumlah_pasien')->default(0);
            $table->integer('urutan')->default(0);
            $table->index('gd_id', 'idx_siaptxn_gdok_dtls_hdr');
            $table->foreign('gd_id', 'fk_siaptxn_gdok_dtls_hdr')->references('gd_id')->on('siaptxn_gdok_hdrs')->onDelete('cascade');
        });

        Schema::create('siaptxn_gdok_lamps', function (Blueprint $table) {
            $table->bigIncrements('lamp_id');
            $table->unsignedBigInteger('gd_id');
            $table->string('komponen', 20);
            $table->string('label', 100)->nullable();
            $table->string('tgl', 10)->nullable();
            $table->string('reg_no', 20)->nullable();
            $table->string('nama', 100)->nullable();
            $table->string('keterangan', 200)->nullable();
            $table->string('klaim', 60)->nullable();
            $table->string('klaim_status', 20)->nullable();
            $table->string('no_sep', 30)->nullable();
            $table->string('txn_no', 30)->nullable();
            $table->decimal('nominal', 14, 2)->default(0);
            $table->integer('pasien')->default(0);
            $table->string('masuk_slip', 1)->default('1');
            $table->index('gd_id', 'idx_siaptxn_gdok_lamps_hdr');
            $table->foreign('gd_id', 'fk_siaptxn_gdok_lamps_hdr')->references('gd_id')->on('siaptxn_gdok_hdrs')->onDelete('cascade');
        });

        Schema::create('siaptxn_gdok_ambils', function (Blueprint $table) {
            $table->bigIncrements('ambil_id');
            $table->string('periode', 7);
            $table->timestamp('diambil_at')->nullable();
            $table->unsignedBigInteger('diambil_oleh')->nullable();
            $table->integer('jumlah_slip')->default(0);
            $table->integer('baru')->default(0);
            $table->integer('berubah')->default(0);
            $table->integer('sama')->default(0);
            $table->integer('lampiran_baris')->default(0);
            $table->string('keterangan', 200)->nullable();
            $table->timestamps();
            $table->index('periode', 'idx_siaptxn_gdok_ambils_per');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('siaptxn_gdok_ambils');
        Schema::dropIfExists('siaptxn_gdok_lamps');
        Schema::dropIfExists('siaptxn_gdok_dtls');
        Schema::dropIfExists('siaptxn_gdok_hdrs');
        Schema::dropIfExists('siapmst_dokter_sirus');
    }
};
