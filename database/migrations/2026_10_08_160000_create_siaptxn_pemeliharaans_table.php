<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pemeliharaan preventif (checklist perawatan alat) — format "Checklist Perawatan Alat" RS:
 * per ruang per bulan, harian (tgl 1–31) atau mingguan (Minggu 1–5), hasil
 * B = Baik, R = Rusak, P = Perlu Perbaikan.
 *
 * - siapmst_assets.pm_frekuensi: H = harian, M = mingguan, '-' = tidak rutin.
 * - siaptxn_pemeliharaans: satu baris per alat per tanggal pemeriksaan
 *   (mingguan: tanggal pemeriksaan di minggu itu; slot = ceil(hari/7)).
 */
return new class extends Migration {
    public function up(): void
    {
        if (!$this->kolomAda('SIAPMST_ASSETS', 'PM_FREKUENSI')) {
            DB::statement("ALTER TABLE siapmst_assets ADD (pm_frekuensi VARCHAR2(1) DEFAULT 'M' NOT NULL)");
            // Alat non medis default tidak rutin.
            DB::table('siapmst_assets')->where('asset_jenis', 'N')->update(['pm_frekuensi' => '-']);
        }

        Schema::create('siaptxn_pemeliharaans', function (Blueprint $table) {
            $table->bigIncrements('pm_id');
            $table->string('asset_id', 30);
            $table->date('pm_tanggal');
            $table->string('pm_hasil', 1); // B / R / P
            $table->string('keterangan', 200)->nullable();
            $table->unsignedBigInteger('petugas_id')->nullable();
            $table->timestamps();

            $table->unique(['asset_id', 'pm_tanggal'], 'uk_siaptxn_pm_asset_tgl');
            $table->foreign('asset_id', 'fk_siaptxn_pm_asset')
                ->references('asset_id')->on('siapmst_assets');
        });

        Schema::table('siaptxn_pemeliharaans', function (Blueprint $table) {
            $table->index('pm_tanggal', 'idx_siaptxn_pm_tgl');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('siaptxn_pemeliharaans');
        if ($this->kolomAda('SIAPMST_ASSETS', 'PM_FREKUENSI')) {
            DB::statement('ALTER TABLE siapmst_assets DROP COLUMN pm_frekuensi');
        }
    }

    private function kolomAda(string $tabel, string $kolom): bool
    {
        return (int) DB::selectOne(
            'SELECT COUNT(*) AS c FROM user_tab_columns WHERE table_name = ? AND column_name = ?',
            [$tabel, $kolom],
        )->c > 0;
    }
};
