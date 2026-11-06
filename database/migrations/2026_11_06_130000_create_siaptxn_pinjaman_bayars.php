<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Buku pembayaran angsuran pinjaman karyawan — SATU baris per angsuran yang terbayar.
 * Angsuran dikenali lewat `periode` (bulan kerja angsuran itu; ke-1 = bulan_awal pinjaman).
 *
 * - sumber GAJI       : dipotong di slip bulan kerja `dipotong_periode` (biasanya = periode; tunggakan / bulan depan bisa
 *                       dipotong di bulan lain). Dibuat / disinkronkan oleh "Terapkan ke Slip" dan tombol Tagih.
 * - sumber TUNAI / TRANSFER / LAIN : dibayar di luar gaji (dicatat Keuangan di tab Pembayaran) → angsuran itu
 *                       TIDAK dipotong lagi di slip.
 * Unik (pinjaman_id, periode): satu angsuran hanya bisa dibayar sekali.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('siaptxn_pinjaman_bayars', function (Blueprint $table) {
            $table->bigIncrements('bayar_id');
            $table->unsignedBigInteger('pinjaman_id');
            $table->string('periode', 7);                       // bulan kerja angsuran yang dibayar (YYYY-MM)
            $table->string('sumber', 10)->default('GAJI');      // GAJI | TUNAI | TRANSFER | LAIN
            $table->string('dipotong_periode', 7)->nullable();  // GAJI: bulan kerja slip tempat dipotong
            $table->decimal('jumlah', 14, 2)->default(0);
            $table->date('tanggal_bayar')->nullable();          // pembayaran luar gaji
            $table->string('keterangan', 200)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique(['pinjaman_id', 'periode'], 'uk_siaptxn_pinjaman_bayars');
            $table->index(['dipotong_periode'], 'idx_siaptxn_pjm_bayars_pot');
            $table->foreign('pinjaman_id', 'fk_siaptxn_pinjaman_bayars_pjm')->references('pinjaman_id')->on('siaptxn_pinjamans')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('siaptxn_pinjaman_bayars');
    }
};
