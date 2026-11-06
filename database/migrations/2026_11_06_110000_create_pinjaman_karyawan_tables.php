<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pinjaman Karyawan — pengganti buku pinjaman koperasi (workbook "Angsuran Tiap Bulan Potong Gaji") dan
 * blok BRI / Koperasi / Rinjani di sheet "angsuran" workbook gaji lama. Logika di App\Support\PinjamanKaryawan.
 *
 * - siapmst_pinjaman_sumbers : MASTER ketentuan per sumber pinjaman (Koperasi, BRI, …): % sewa modal per bulan,
 *                              pembulatan angsuran, kode potongan slip. Ubah di sini bila ketentuan berubah.
 * - siaptxn_pinjamans        : buku pinjaman per karyawan — pokok, % sewa (snapshot saat pinjam), lama bulan,
 *                              bulan awal potong; lunas_periode = mulai bulan itu tidak dipotong lagi (pelunasan dipercepat).
 * - siaptxn_pinjaman_hdrs    : jejak "Terapkan ke Slip" per bulan.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('siapmst_pinjaman_sumbers', function (Blueprint $table) {
            $table->string('sumber_kode', 10)->primary('pk_siapmst_pinjaman_sumbers');
            $table->string('nama', 40);
            $table->decimal('persen_sewa', 6, 3)->default(0);     // sewa modal / bunga per bulan, % dari pokok
            $table->integer('pembulatan')->default(1000);         // angsuran dibulatkan ke atas ke kelipatan ini
            $table->string('kode_potongan', 20);                  // kode baris slip: ANGS_KOP | ANGS_BANK | ANGSURAN
            $table->integer('urutan')->default(0);
            $table->string('active_status', 1)->default('1');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });

        Schema::create('siaptxn_pinjamans', function (Blueprint $table) {
            $table->bigIncrements('pinjaman_id');
            $table->string('emp_id', 20);
            $table->string('sumber_kode', 10);
            $table->decimal('pokok', 14, 2)->default(0);
            $table->decimal('persen_sewa', 6, 3)->default(0);     // snapshot ketentuan saat pinjam, boleh diubah per pinjaman
            $table->integer('lama_bulan')->default(1);
            $table->string('bulan_awal', 7);                      // YYYY-MM bulan kerja angsuran ke-1
            $table->string('lunas_periode', 7)->nullable();       // YYYY-MM: mulai bulan ini tidak dipotong (pelunasan dipercepat)
            $table->string('keterangan', 200)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->index(['emp_id', 'bulan_awal'], 'idx_siaptxn_pinjamans_emp');
            $table->foreign('emp_id', 'fk_siaptxn_pinjamans_emp')->references('emp_id')->on('siapmst_employers');
            $table->foreign('sumber_kode', 'fk_siaptxn_pinjamans_sumber')->references('sumber_kode')->on('siapmst_pinjaman_sumbers');
        });

        Schema::create('siaptxn_pinjaman_hdrs', function (Blueprint $table) {
            $table->string('periode', 7)->primary('pk_siaptxn_pinjaman_hdrs');
            $table->timestamp('diterapkan_at')->nullable();
            $table->unsignedBigInteger('diterapkan_oleh')->nullable();
            $table->timestamps();
        });

        // Ketentuan awal — meniru workbook lama: Koperasi 0,8 % (sheet "0,8%"; pinjaman lama 1 % disimpan per pinjaman), BRI & Rinjani tanpa sewa modal.
        foreach (\App\Support\PinjamanKaryawan::SUMBER_BAWAAN as $sumber) {
            DB::table('siapmst_pinjaman_sumbers')->insert([...$sumber, 'active_status' => '1', 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('siaptxn_pinjaman_hdrs');
        Schema::dropIfExists('siaptxn_pinjamans');
        Schema::dropIfExists('siapmst_pinjaman_sumbers');
    }
};
