<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pemindahan penggajian dokter dari SIRUS ke SIAP, bertahap (App\Support\GajiDokterStruktur, GajiDokterHitung, PengaturanGajiDokter):
 *
 *   tahap 1  siapmst_gdok_strukturs  : struktur gaji per dokter (salinan kolom gaji RSMST_DOCTORS SIRUS) — disetel di SIAP,
 *                                      dikirim ke SIRUS lewat PUT /api/siap/dokter/{dr_id}/struktur-gaji.
 *            siapmst_pengaturan_gdoks: satu baris saklar — sumber_struktur (SIRUS|SIAP) & mesin_slip (SIRUS|SIAP).
 *   tahap 2  siaptxn_gdok_lamps.grup : group_doc transaksi (RJ/RI/OK/…) supaya SIAP bisa menghitung kapita per jalur.
 *   tahap 3  siaptxn_gdok_hdrs.sumber: SIRUS (tarikan) | SIAP (dihitung SIAP dari transaksi mentah); difinalkan_* status Final di SIAP.
 *            siaptxn_gdok_manuals    : baris potongan / tambahan manual per dokter per bulan jasa (kasbon, bonus, rapel, …)
 *                                      yang ikut dihitung saat Proses Slip di SIAP.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('siapmst_gdok_strukturs', function (Blueprint $table) {
            $table->string('dr_id', 10)->primary();
            $table->decimal('basic_salary', 14, 2)->default(0);
            $table->string('npwp_status', 1)->default('Y');
            $table->string('npwp', 30)->nullable();
            $table->string('skema_gaji_pokok', 1)->default('A');       // A aditif | G garanty fee | N tanpa pokok
            $table->string('potongan_rs_basis', 1)->default('T');      // T total | J jasa | N tidak | B berjenjang
            $table->decimal('potongan_rs_persen', 5, 2)->default(10);
            $table->string('potongan_rs_aturan', 1000)->nullable();    // JSON {"UP RJ":10,"JD RJ":"Rp500000"} bila B
            $table->decimal('pph21_persen', 5, 2)->default(2.5);
            $table->decimal('tarif_per_kapita_ri', 12, 2)->default(0);
            $table->decimal('tarif_per_kapita_rj', 12, 2)->default(0);
            $table->decimal('tunjangan_struktural', 14, 2)->default(0);
            $table->decimal('tunjangan_fungsional', 14, 2)->default(0);
            $table->decimal('tunjangan_hadir', 14, 2)->default(0);
            $table->decimal('potongan_idi', 14, 2)->default(0);
            $table->decimal('potongan_arisan', 14, 2)->default(0);
            $table->decimal('potongan_koperasi', 14, 2)->default(0);
            $table->decimal('potongan_angsuran', 14, 2)->default(0);
            $table->decimal('potongan_bpjs', 14, 2)->default(0);
            $table->decimal('potongan_zariyah', 14, 2)->default(0);
            $table->string('sumber', 5)->default('SIRUS');            // SIRUS = salinan tarikan | SIAP = disetel di SIAP
            $table->timestamp('disinkron_at')->nullable();             // terakhir berhasil dikirim ke SIRUS
            $table->string('sinkron_pesan', 200)->nullable();          // galat kirim terakhir (null = beres)
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->foreign('dr_id', 'fk_siapmst_gdok_strukturs_dr')->references('dr_id')->on('siapmst_dokter_sirus');
        });

        Schema::create('siapmst_pengaturan_gdoks', function (Blueprint $table) {
            $table->increments('id');
            $table->string('sumber_struktur', 5)->default('SIRUS');
            $table->string('mesin_slip', 5)->default('SIRUS');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
        DB::table('siapmst_pengaturan_gdoks')->insert(['id' => 1, 'sumber_struktur' => 'SIRUS', 'mesin_slip' => 'SIRUS', 'created_at' => now(), 'updated_at' => now()]);

        Schema::table('siaptxn_gdok_lamps', function (Blueprint $table) {
            $table->string('grup', 10)->nullable();
        });

        Schema::table('siaptxn_gdok_hdrs', function (Blueprint $table) {
            $table->string('sumber', 5)->default('SIRUS');
            $table->timestamp('difinalkan_at')->nullable();
            $table->unsignedBigInteger('difinalkan_oleh')->nullable();
        });

        Schema::create('siaptxn_gdok_manuals', function (Blueprint $table) {
            $table->bigIncrements('manual_id');
            $table->string('periode', 7);
            $table->string('dr_id', 10);
            $table->string('jenis', 1);                 // P potongan | T tambahan
            $table->string('kode', 20);
            $table->string('keterangan', 100)->nullable();
            $table->decimal('nilai', 14, 2)->default(0); // disimpan POSITIF; tanda diurus mesin hitung
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->index(['periode', 'dr_id'], 'idx_siaptxn_gdok_manuals_per');
            $table->foreign('dr_id', 'fk_siaptxn_gdok_manuals_dr')->references('dr_id')->on('siapmst_dokter_sirus');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('siaptxn_gdok_manuals');
        Schema::table('siaptxn_gdok_hdrs', fn(Blueprint $table) => $table->dropColumn(['sumber', 'difinalkan_at', 'difinalkan_oleh']));
        Schema::table('siaptxn_gdok_lamps', fn(Blueprint $table) => $table->dropColumn('grup'));
        Schema::dropIfExists('siapmst_pengaturan_gdoks');
        Schema::dropIfExists('siapmst_gdok_strukturs');
    }
};
